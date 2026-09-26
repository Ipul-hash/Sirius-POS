<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PosSecurityService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function voidOrder(array $data, ?int $currentUserId = null): array
    {
        return DB::transaction(function () use ($data, $currentUserId) {
            $supervisorPin = (string) $data['supervisor_pin'];
            $supervisor = null;

            if (auth()->check() && auth()->user()->isSupervisor() && Hash::check($supervisorPin, auth()->user()->pin_code)) {
                $supervisor = auth()->user();
            } else {
                $candidates = User::whereIn('role', ['supervisor', 'admin'])
                    ->where('is_active', true)
                    ->get();

                foreach ($candidates as $candidate) {
                    if ($candidate->pin_code && Hash::check($supervisorPin, $candidate->pin_code)) {
                        $supervisor = $candidate;
                        break;
                    }
                }
            }

            if (! $supervisor) {
                throw ValidationException::withMessages([
                    'supervisor_pin' => 'PIN Supervisor tidak valid atau otorisasi ditolak.',
                ]);
            }

            $order = Order::with(['cashShift', 'items', 'customer', 'kdsTicket'])->findOrFail($data['order_id']);

            if ($order->is_void) {
                throw ValidationException::withMessages([
                    'order_id' => 'Order ini sudah dibatalkan (void) sebelumnya.',
                ]);
            }

            $cashierId = $currentUserId
                ?? auth()->id()
                ?? $order->cashier_id
                ?? User::where('role', 'cashier')->first()?->id
                ?? 1;

            $order->update([
                'is_void' => true,
                'payment_status' => 'void',
                'void_reason' => $data['reason'],
                'void_by' => $supervisor->id,
            ]);

            $stockMovements = StockMovement::where('reference_id', $order->id)
                ->whereIn('reference_type', ['POS_SALE', 'POS_BOM_CONSUMPTION'])
                ->get();

            foreach ($stockMovements as $sm) {
                if ($sm->batch_id) {
                    $batch = InventoryBatch::find($sm->batch_id);
                    if ($batch) {
                        $newBatchQty = round((float) $batch->current_qty + (float) $sm->quantity, 4);
                        $batch->update([
                            'current_qty' => $newBatchQty,
                            'status' => $newBatchQty > 0 ? 'active' : $batch->status,
                        ]);
                    }
                }

                StockMovement::create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $sm->product_id,
                    'batch_id' => $sm->batch_id,
                    'from_location_id' => null,
                    'to_location_id' => $sm->from_location_id,
                    'reference_type' => 'POS_VOID_RETURN',
                    'reference_id' => $order->id,
                    'quantity' => $sm->quantity,
                    'unit_cost' => $sm->unit_cost,
                    'total_cost' => $sm->total_cost,
                    'notes' => "Pengembalian stok void order #{$order->invoice_number}: {$data['reason']}",
                    'created_by' => $supervisor->id,
                    'created_at' => Carbon::now(),
                ]);
            }

            if ($order->customer) {
                $pointsEarned = (int) floor((float) $order->total_amount / 10000);
                if ($pointsEarned > 0) {
                    $order->customer->adjustPoints(
                        points: -$pointsEarned,
                        type: 'redeem',
                        division: 'general',
                        orderId: $order->id,
                        description: "Pembatalan poin void order #{$order->invoice_number}"
                    );
                    $order->customer->total_spent = max(0, round((float) $order->customer->total_spent - (float) $order->total_amount, 2));
                    $order->customer->save();
                }
            }

            if ($order->kdsTicket) {
                $order->kdsTicket->update([
                    'status' => 'collected',
                    'notes' => 'DIBATALKAN / VOID: '.$data['reason'],
                ]);
            }

            $cashShift = $order->cashShift;
            if ($cashShift) {
                $cashShift->recalculateTotals();
                $cashShift->refresh();
            }

            $auditLog = AuditLog::create([
                'branch_id' => $order->branch_id,
                'user_id' => $cashierId,
                'supervisor_id' => $supervisor->id,
                'action' => 'VOID_ORDER',
                'reference_type' => 'Order',
                'reference_id' => $order->id,
                'reason' => $data['reason'],
                'payload' => [
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'total_amount' => (float) $order->total_amount,
                    'payment_method' => $order->payment_method,
                    'payment_status' => 'void',
                    'cash_shift_id' => $order->cash_shift_id,
                    'supervisor_id' => $supervisor->id,
                    'supervisor_name' => $supervisor->name,
                    'cashier_id' => $cashierId,
                    'reason' => $data['reason'],
                ],
                'ip_address' => request()->ip(),
                'created_at' => Carbon::now(),
            ]);

            return [
                'order' => [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'total_amount' => (float) $order->total_amount,
                    'payment_status' => $order->payment_status,
                    'is_void' => (bool) $order->is_void,
                    'void_reason' => $order->void_reason,
                    'void_by' => [
                        'id' => $supervisor->id,
                        'name' => $supervisor->name,
                        'role' => $supervisor->role,
                    ],
                ],
                'cash_shift' => $cashShift ? [
                    'id' => $cashShift->id,
                    'total_cash_sales' => (float) $cashShift->total_cash_sales,
                    'total_qris_sales' => (float) $cashShift->total_qris_sales,
                    'expected_cash_in_drawer' => (float) $cashShift->expected_cash_in_drawer,
                ] : null,
                'audit_log' => [
                    'id' => $auditLog->id,
                    'action' => $auditLog->action,
                    'supervisor_id' => $supervisor->id,
                    'supervisor_name' => $supervisor->name,
                    'cashier_id' => $cashierId,
                    'reason' => $auditLog->reason,
                    'created_at' => $auditLog->created_at->toISOString(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function openDrawer(array $data, ?int $currentUserId = null): array
    {
        return DB::transaction(function () use ($data, $currentUserId) {
            $branchId = (int) $data['branch_id'];
            $branch = Branch::findOrFail($branchId);

            $shift = CashShift::where('branch_id', $branchId)->open()->latest()->first();

            $cashier = auth()->user()
                ?? ($currentUserId ? User::find($currentUserId) : null)
                ?? $shift?->user
                ?? User::where('role', 'cashier')->first()
                ?? User::first();

            $auditLog = AuditLog::create([
                'branch_id' => $branch->id,
                'user_id' => $cashier->id,
                'supervisor_id' => null,
                'action' => 'OPEN_DRAWER_NO_SALE',
                'reference_type' => 'CashShift',
                'reference_id' => $shift?->id,
                'reason' => $data['reason'],
                'payload' => [
                    'branch_code' => $branch->code,
                    'branch_name' => $branch->name,
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'shift_id' => $shift?->id,
                    'expected_cash_in_drawer' => $shift ? (float) $shift->expected_cash_in_drawer : null,
                    'reason' => $data['reason'],
                ],
                'ip_address' => request()->ip(),
                'created_at' => Carbon::now(),
            ]);

            return [
                'audit_log' => [
                    'id' => $auditLog->id,
                    'action' => $auditLog->action,
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'cash_shift_id' => $shift?->id,
                    'reason' => $auditLog->reason,
                    'created_at' => $auditLog->created_at->toISOString(),
                ],
                'drawer_status' => 'opened',
            ];
        });
    }
}
