<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\KdsTicket;
use App\Models\KdsTicketItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosCheckoutService
{
    public function __construct(
        protected CartCalculationService $cartCalculationService,
        protected ReceiptFormatterService $receiptFormatterService
    ) {}

    public function checkout(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $branchId = (int) ($data['branch_id'] ?? 1);
            $branch = Branch::findOrFail($branchId);

            $shift = CashShift::where('branch_id', $branchId)->open()->latest()->first();

            $cashier = auth()->user()
                ?? $shift?->user
                ?? User::where('role', 'cashier')->first()
                ?? User::first();

            if (! $cashier) {
                throw new Exception('Petugas kasir tidak ditemukan di sistem.');
            }

            if (! $shift) {
                $shift = CashShift::create([
                    'branch_id' => $branchId,
                    'user_id' => $cashier->id,
                    'start_time' => Carbon::now(),
                    'opening_balance' => 0.00,
                    'expected_cash_in_drawer' => 0.00,
                    'status' => 'open',
                    'notes' => 'Auto-opened for POS Checkout',
                ]);
            }

            $customer = null;
            $customerPhone = ! empty($data['customer_phone']) ? trim($data['customer_phone']) : null;
            if ($customerPhone) {
                $customerName = ! empty($data['customer_name'])
                    ? trim($data['customer_name'])
                    : 'Pelanggan '.substr($customerPhone, -4);

                $customer = Customer::firstOrCreate(
                    ['phone' => $customerPhone],
                    ['name' => $customerName, 'current_points' => 0, 'total_spent' => 0]
                );
            }

            $cartResult = $this->cartCalculationService->calculate($data['items'], $branchId);
            $summary = $cartResult['summary'];
            $totalAmount = (float) $summary['total_amount'];

            $paymentMethod = strtolower($data['payment_method'] ?? 'cash');
            $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $totalAmount;

            if ($paymentMethod === 'cash' && $amountPaid < $totalAmount) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Jumlah pembayaran tunai ('.number_format($amountPaid, 0, ',', '.').') kurang dari total belanja ('.number_format($totalAmount, 0, ',', '.').').',
                ]);
            }

            $changeAmount = max(0, round($amountPaid - $totalAmount, 2));

            $retailLocation = Location::where('branch_id', $branchId)
                ->where('code', 'RAK-FRONT')
                ->first()
                ?? Location::where('branch_id', $branchId)->where('division', 'retail')->first()
                ?? Location::where('branch_id', $branchId)->first();

            $coffeeLocation = Location::where('branch_id', $branchId)
                ->where('code', 'BAR-COUNTER')
                ->first()
                ?? Location::where('branch_id', $branchId)->where('division', 'coffee')->first()
                ?? Location::where('branch_id', $branchId)->first();

            $hasCoffee = collect($cartResult['items'])->contains(fn ($it) => $it['division'] === 'coffee');

            $todayOrdersCount = Order::where('branch_id', $branchId)
                ->whereDate('created_at', Carbon::today())
                ->count();
            $invoiceNumber = 'INV-'.date('Ymd').'-'.str_pad((string) ($todayOrdersCount + 1), 4, '0', STR_PAD_LEFT);

            $queueNumber = null;
            if ($hasCoffee) {
                if (! empty($data['queue_number'])) {
                    $queueNumber = $data['queue_number'];
                } else {
                    $todayKdsCount = KdsTicket::where('branch_id', $branchId)
                        ->whereDate('created_at', Carbon::today())
                        ->count();
                    $queueNumber = 'C-'.str_pad((string) ($todayKdsCount + 1), 3, '0', STR_PAD_LEFT);
                }
            }

            $order = Order::create([
                'invoice_number' => $invoiceNumber,
                'branch_id' => $branchId,
                'cash_shift_id' => $shift->id,
                'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'queue_number' => $queueNumber,
                'subtotal' => $summary['subtotal'],
                'discount_amount' => $summary['discount_amount'],
                'tax_amount' => $summary['tax_amount'],
                'total_amount' => $totalAmount,
                'retail_subtotal' => $summary['division_breakdown']['retail']['subtotal'],
                'coffee_subtotal' => $summary['division_breakdown']['coffee']['subtotal'],
                'retail_cogs' => 0.00,
                'coffee_cogs' => 0.00,
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'order_date' => Carbon::now(),
            ]);

            $totalRetailCogs = 0.0;
            $totalCoffeeCogs = 0.0;
            $coffeeOrderItems = [];

            foreach ($cartResult['items'] as $cartItem) {
                $productId = (int) $cartItem['product_id'];
                $quantity = (float) $cartItem['quantity'];
                $division = $cartItem['division'];
                $product = Product::with('boms.materialProduct')->findOrFail($productId);

                $itemCostPrice = 0.0;

                if ($division === 'retail') {
                    $consumedCost = $this->deductStockViaFefo(
                        branchId: $branchId,
                        productId: $productId,
                        locationId: $retailLocation->id,
                        quantity: $quantity,
                        orderId: $order->id,
                        cashierId: $cashier->id,
                        referenceType: 'POS_SALE',
                        notes: "Penjualan Kasir POS {$product->name} (Inv #{$order->invoice_number})"
                    );

                    $totalRetailCogs += $consumedCost;
                    $itemCostPrice = $quantity > 0 ? round($consumedCost / $quantity, 2) : 0.0;
                } else {
                    $lineCoffeeCost = 0.0;

                    foreach ($product->boms as $bom) {
                        $materialProductId = (int) $bom->material_product_id;
                        $yieldLossMultiplier = 1 + ((float) $bom->yield_loss_percentage / 100);
                        $requiredMaterialQty = round($quantity * (float) $bom->quantity * $yieldLossMultiplier, 4);

                        $bomConsumedCost = $this->deductStockViaFefo(
                            branchId: $branchId,
                            productId: $materialProductId,
                            locationId: $coffeeLocation->id,
                            quantity: $requiredMaterialQty,
                            orderId: $order->id,
                            cashierId: $cashier->id,
                            referenceType: 'POS_BOM_CONSUMPTION',
                            notes: "BOM Konsumsi {$product->name} x{$quantity} (Inv #{$order->invoice_number})"
                        );

                        $lineCoffeeCost += $bomConsumedCost;
                    }

                    $totalCoffeeCogs += $lineCoffeeCost;
                    $itemCostPrice = $quantity > 0 ? round($lineCoffeeCost / $quantity, 2) : 0.0;
                }

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'division' => $division,
                    'quantity' => $quantity,
                    'unit_price' => $cartItem['unit_price'],
                    'cost_price' => $itemCostPrice,
                    'subtotal' => $cartItem['subtotal'],
                    'discount_amount' => $cartItem['discount_amount'],
                    'notes' => $cartItem['notes'],
                ]);

                if ($division === 'coffee') {
                    $coffeeOrderItems[] = $orderItem;
                }
            }

            $order->update([
                'retail_cogs' => round($totalRetailCogs, 2),
                'coffee_cogs' => round($totalCoffeeCogs, 2),
            ]);

            $payment = Payment::create([
                'order_id' => $order->id,
                'payment_method' => $paymentMethod,
                'amount' => $amountPaid,
                'change_amount' => $changeAmount,
                'reference_no' => $data['reference_no'] ?? ($paymentMethod === 'qris' ? 'QRIS-'.time() : null),
                'created_at' => Carbon::now(),
            ]);

            $pointsEarned = 0;
            if ($customer) {
                $pointsEarned = (int) floor($totalAmount / 10000);
                if ($pointsEarned > 0) {
                    $customer->adjustPoints(
                        points: $pointsEarned,
                        type: 'earn',
                        division: 'general',
                        orderId: $order->id,
                        description: "Loyalty point POS order #{$order->invoice_number}"
                    );
                }
                $customer->total_spent = (float) $customer->total_spent + $totalAmount;
                $customer->save();
            }

            $kdsTicket = null;
            if ($hasCoffee && count($coffeeOrderItems) > 0) {
                $kdsTicket = KdsTicket::create([
                    'order_id' => $order->id,
                    'branch_id' => $branchId,
                    'queue_number' => $queueNumber,
                    'status' => 'queued',
                    'notes' => 'Pesanan dari Kasir POS',
                ]);

                foreach ($coffeeOrderItems as $cItem) {
                    KdsTicketItem::create([
                        'ticket_id' => $kdsTicket->id,
                        'order_item_id' => $cItem->id,
                        'product_id' => $cItem->product_id,
                        'quantity' => (int) $cItem->quantity,
                        'custom_notes' => $cItem->notes,
                        'status' => 'queued',
                    ]);
                }
            }

            $shift->recalculateTotals();

            $order->setRelation('branch', $branch);
            $order->setRelation('cashier', $cashier);
            $order->setRelation('customer', $customer);
            $order->setRelation('payments', collect([$payment]));

            $receiptText = $this->receiptFormatterService->format($order);

            $retailSubtotal = (float) $order->retail_subtotal;
            $retailCogs = (float) $order->retail_cogs;
            $retailGrossProfit = round($retailSubtotal - $retailCogs, 2);
            $retailMarginPct = $retailSubtotal > 0 ? round(($retailGrossProfit / $retailSubtotal) * 100, 2) : 0.0;

            $coffeeSubtotal = (float) $order->coffee_subtotal;
            $coffeeCogs = (float) $order->coffee_cogs;
            $coffeeGrossProfit = round($coffeeSubtotal - $coffeeCogs, 2);
            $coffeeMarginPct = $coffeeSubtotal > 0 ? round(($coffeeGrossProfit / $coffeeSubtotal) * 100, 2) : 0.0;

            $totalGrossProfit = round($retailGrossProfit + $coffeeGrossProfit, 2);
            $totalCogs = round($retailCogs + $coffeeCogs, 2);

            return [
                'order' => [
                    'id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'order_date' => $order->order_date->format('Y-m-d H:i:s'),
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'points_earned' => $pointsEarned,
                        'current_points' => $customer->current_points,
                    ] : null,
                    'queue_number' => $queueNumber,
                    'subtotal' => (float) $order->subtotal,
                    'discount_amount' => (float) $order->discount_amount,
                    'tax_amount' => (float) $order->tax_amount,
                    'total_amount' => (float) $order->total_amount,
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $amountPaid,
                    'change_amount' => $changeAmount,
                    'items' => $order->items->map(function ($it) {
                        return [
                            'id' => $it->id,
                            'product_id' => $it->product_id,
                            'name' => $it->product?->name ?? 'Product',
                            'division' => $it->division,
                            'quantity' => (float) $it->quantity,
                            'unit_price' => (float) $it->unit_price,
                            'cost_price' => (float) $it->cost_price,
                            'discount_amount' => (float) $it->discount_amount,
                            'subtotal' => (float) $it->subtotal,
                            'notes' => $it->notes,
                        ];
                    })->toArray(),
                ],
                'division_breakdown' => [
                    'retail' => [
                        'omset' => $retailSubtotal,
                        'cogs' => $retailCogs,
                        'gross_profit' => $retailGrossProfit,
                        'margin_percentage' => $retailMarginPct,
                    ],
                    'coffee' => [
                        'omset' => $coffeeSubtotal,
                        'cogs' => $coffeeCogs,
                        'gross_profit' => $coffeeGrossProfit,
                        'margin_percentage' => $coffeeMarginPct,
                    ],
                    'total_omset' => (float) $order->total_amount,
                    'total_cogs' => $totalCogs,
                    'total_gross_profit' => $totalGrossProfit,
                ],
                'kds_ticket' => $kdsTicket ? [
                    'id' => $kdsTicket->id,
                    'queue_number' => $kdsTicket->queue_number,
                    'status' => $kdsTicket->status,
                    'items' => $kdsTicket->items->map(fn ($ki) => [
                        'product_id' => $ki->product_id,
                        'product_name' => $ki->product?->name ?? 'Coffee',
                        'quantity' => $ki->quantity,
                        'custom_notes' => $ki->custom_notes,
                    ])->toArray(),
                ] : null,
                'receipt_text' => $receiptText,
            ];
        });
    }

    protected function deductStockViaFefo(
        int $branchId,
        int $productId,
        int $locationId,
        float $quantity,
        int $orderId,
        int $cashierId,
        string $referenceType,
        string $notes
    ): float {
        $remainingQty = $quantity;
        $totalCost = 0.0;

        $batches = InventoryBatch::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->active()
            ->fefo()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingQty <= 0) {
                break;
            }

            $currentBatchQty = (float) $batch->current_qty;
            $qtyToDeduct = min($remainingQty, $currentBatchQty);
            $unitCost = (float) $batch->unit_cost;
            $movementCost = round($qtyToDeduct * $unitCost, 2);

            $newBatchQty = round($currentBatchQty - $qtyToDeduct, 4);
            $batch->update([
                'current_qty' => $newBatchQty,
                'status' => $newBatchQty <= 0 ? 'depleted' : 'active',
            ]);

            StockMovement::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'batch_id' => $batch->id,
                'from_location_id' => $locationId,
                'to_location_id' => null,
                'reference_type' => $referenceType,
                'reference_id' => $orderId,
                'quantity' => $qtyToDeduct,
                'unit_cost' => $unitCost,
                'total_cost' => $movementCost,
                'notes' => $notes,
                'created_by' => $cashierId,
                'created_at' => Carbon::now(),
            ]);

            $totalCost += $movementCost;
            $remainingQty = round($remainingQty - $qtyToDeduct, 4);
        }

        if ($remainingQty > 0) {
            $product = Product::find($productId);
            $fallbackUnitCost = (float) ($product?->purchase_price ?? 0.0);
            $fallbackCost = round($remainingQty * $fallbackUnitCost, 2);

            StockMovement::create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'batch_id' => null,
                'from_location_id' => $locationId,
                'to_location_id' => null,
                'reference_type' => $referenceType,
                'reference_id' => $orderId,
                'quantity' => $remainingQty,
                'unit_cost' => $fallbackUnitCost,
                'total_cost' => $fallbackCost,
                'notes' => $notes.' (Batch stock deficit consumed)',
                'created_by' => $cashierId,
                'created_at' => Carbon::now(),
            ]);

            $totalCost += $fallbackCost;
        }

        return round($totalCost, 2);
    }
}
