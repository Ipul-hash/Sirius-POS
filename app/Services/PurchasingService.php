<?php

namespace App\Services;

use App\Models\ApInvoice;
use App\Models\GoodsReceiptNote;
use App\Models\GoodsReceiptNoteItem;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchasingService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createPurchaseOrder(array $data, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $branchId = (int) ($data['branch_id'] ?? 1);
            $orderDate = $data['order_date'] ?? Carbon::today()->toDateString();
            $expectedDeliveryDate = $data['expected_delivery_date'] ?? null;

            $creatorId = $userId
                ?? auth()->id()
                ?? User::where('role', 'admin')->first()?->id
                ?? 1;

            $seq = (int) PurchaseOrder::max('id') + 1;
            do {
                $poNumber = 'PO-'.Carbon::today()->format('Ym').'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                $seq++;
            } while (PurchaseOrder::where('po_number', $poNumber)->exists());

            $purchaseOrder = PurchaseOrder::create([
                'po_number' => $poNumber,
                'supplier_id' => $supplier->id,
                'branch_id' => $branchId,
                'order_date' => $orderDate,
                'expected_delivery_date' => $expectedDeliveryDate,
                'total_amount' => 0.00,
                'status' => 'sent',
                'created_by' => $creatorId,
                'notes' => $data['notes'] ?? null,
            ]);

            $totalAmount = 0.0;

            foreach ($data['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $product = Product::findOrFail($productId);
                $orderedQty = (float) $itemData['ordered_qty'];

                $unitId = isset($itemData['unit_id'])
                    ? (int) $itemData['unit_id']
                    : $product->primary_unit_id;

                $unitPrice = isset($itemData['unit_price'])
                    ? (float) $itemData['unit_price']
                    : (float) $product->purchase_price;

                $subtotal = round($orderedQty * $unitPrice, 2);
                $totalAmount += $subtotal;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product_id' => $productId,
                    'ordered_qty' => $orderedQty,
                    'received_qty' => 0.0000,
                    'unit_id' => $unitId,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);
            }

            $purchaseOrder->update([
                'total_amount' => round($totalAmount, 2),
            ]);

            return $purchaseOrder->load(['supplier', 'branch', 'creator', 'items.product', 'items.unit']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function receiveGoods(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $po = PurchaseOrder::with(['items', 'supplier', 'branch'])->findOrFail($data['purchase_order_id']);

            $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
            if (! $locationId) {
                $defaultLocation = Location::where('branch_id', $po->branch_id)->where('code', 'GUDANG-BELAKANG')->first()
                    ?? Location::where('branch_id', $po->branch_id)->where('code', 'RAK-FRONT')->first()
                    ?? Location::where('branch_id', $po->branch_id)->first();
                $locationId = $defaultLocation?->id ?? 1;
            }

            $location = Location::findOrFail($locationId);

            $receiverId = $userId
                ?? auth()->id()
                ?? User::where('role', 'admin')->first()?->id
                ?? 1;

            $grnSeq = (int) GoodsReceiptNote::max('id') + 1;
            do {
                $grnNumber = 'GRN-'.Carbon::today()->format('Ym').'-'.str_pad((string) $grnSeq, 3, '0', STR_PAD_LEFT);
                $grnSeq++;
            } while (GoodsReceiptNote::where('grn_number', $grnNumber)->exists());

            $grn = GoodsReceiptNote::create([
                'grn_number' => $grnNumber,
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'location_id' => $location->id,
                'receipt_date' => $data['receipt_date'] ?? Carbon::now(),
                'received_by' => $receiverId,
                'invoice_ref_number' => $data['invoice_ref_number'],
                'status' => 'verified',
                'notes' => $data['notes'] ?? null,
            ]);

            $totalGrnAmount = 0.0;
            $itemsResponse = [];

            foreach ($data['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $product = Product::findOrFail($productId);
                $receivedQty = (float) $itemData['received_qty'];

                $poItem = null;
                if (! empty($itemData['po_item_id'])) {
                    $poItem = PurchaseOrderItem::find($itemData['po_item_id']);
                }
                if (! $poItem) {
                    $poItem = $po->items->firstWhere('product_id', $productId);
                }

                $unitCost = isset($itemData['unit_cost'])
                    ? (float) $itemData['unit_cost']
                    : (float) ($poItem?->unit_price ?? $product->purchase_price);

                $subtotal = round($receivedQty * $unitCost, 2);
                $totalGrnAmount += $subtotal;

                $batchNo = ! empty($itemData['batch_no'])
                    ? trim($itemData['batch_no'])
                    : ('B-'.strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $product->sku)).'-'.Carbon::today()->format('ymd'));

                $expiredAt = ! empty($itemData['expired_at']) ? $itemData['expired_at'] : null;

                $batch = InventoryBatch::create([
                    'batch_no' => $batchNo,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'initial_qty' => $receivedQty,
                    'current_qty' => $receivedQty,
                    'unit_cost' => $unitCost,
                    'expired_at' => $expiredAt,
                    'status' => 'active',
                ]);

                $grnItem = GoodsReceiptNoteItem::create([
                    'grn_id' => $grn->id,
                    'po_item_id' => $poItem?->id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'batch_no' => $batchNo,
                    'expired_at' => $expiredAt,
                    'received_qty' => $receivedQty,
                    'unit_id' => $product->primary_unit_id,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                ]);

                if ($poItem) {
                    $poItem->update([
                        'received_qty' => round((float) $poItem->received_qty + $receivedQty, 4),
                    ]);
                }

                StockMovement::create([
                    'branch_id' => $po->branch_id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'from_location_id' => null,
                    'to_location_id' => $location->id,
                    'reference_type' => 'PO_RECEIPT',
                    'reference_id' => $grn->id,
                    'quantity' => $receivedQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $subtotal,
                    'notes' => "Penerimaan PO #{$po->po_number} SJ #{$data['invoice_ref_number']}",
                    'created_by' => $receiverId,
                    'created_at' => Carbon::now(),
                ]);

                $itemsResponse[] = [
                    'id' => $grnItem->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'batch_id' => $batch->id,
                    'batch_no' => $batchNo,
                    'expired_at' => $expiredAt,
                    'received_qty' => $receivedQty,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                ];
            }

            $po->refresh();
            $allReceived = $po->items->every(fn ($pi) => (float) $pi->received_qty >= (float) $pi->ordered_qty);
            $po->update([
                'status' => $allReceived ? 'received' : 'partially_received',
            ]);

            $apSeq = (int) ApInvoice::max('id') + 1;
            do {
                $apInvoiceNumber = 'INV-AP-'.Carbon::today()->format('Ym').'-'.str_pad((string) $apSeq, 3, '0', STR_PAD_LEFT);
                $apSeq++;
            } while (ApInvoice::where('invoice_number', $apInvoiceNumber)->exists());

            $paymentTermsDays = $po->supplier?->payment_terms_days ?? 14;
            $dueDate = Carbon::today()->addDays($paymentTermsDays);

            $apInvoice = ApInvoice::create([
                'invoice_number' => $apInvoiceNumber,
                'supplier_id' => $po->supplier_id,
                'branch_id' => $po->branch_id,
                'grn_id' => $grn->id,
                'issue_date' => Carbon::today(),
                'due_date' => $dueDate,
                'total_amount' => round($totalGrnAmount, 2),
                'paid_amount' => 0.00,
                'status' => 'unpaid',
                'notes' => "Tagihan atas GRN #{$grn->grn_number} (PO #{$po->po_number}, SJ: {$data['invoice_ref_number']})",
            ]);

            return [
                'goods_receipt_note' => [
                    'id' => $grn->id,
                    'grn_number' => $grn->grn_number,
                    'purchase_order_id' => $po->id,
                    'po_number' => $po->po_number,
                    'po_status' => $po->status,
                    'supplier_name' => $po->supplier?->name,
                    'location_name' => $location->name,
                    'invoice_ref_number' => $grn->invoice_ref_number,
                    'receipt_date' => $grn->receipt_date->toISOString(),
                    'status' => $grn->status,
                    'total_amount' => round($totalGrnAmount, 2),
                ],
                'items' => $itemsResponse,
                'ap_invoice' => [
                    'id' => $apInvoice->id,
                    'invoice_number' => $apInvoice->invoice_number,
                    'supplier_name' => $po->supplier?->name,
                    'issue_date' => $apInvoice->issue_date?->toDateString(),
                    'due_date' => $apInvoice->due_date?->toDateString(),
                    'payment_terms_days' => $paymentTermsDays,
                    'total_amount' => (float) $apInvoice->total_amount,
                    'status' => $apInvoice->status,
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getApAlerts(array $filters = []): array
    {
        $daysThreshold = (int) ($filters['days_threshold'] ?? 7);
        $branchId = (int) ($filters['branch_id'] ?? 1);
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;

        $targetDate = Carbon::today()->addDays($daysThreshold);

        $query = ApInvoice::with(['supplier', 'branch'])
            ->unpaid()
            ->where('branch_id', $branchId)
            ->where('due_date', '<=', $targetDate);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $invoices = $query->orderBy('due_date', 'asc')->get();

        $alerts = [];
        $totalOverdueAmount = 0.0;
        $totalDueSoonAmount = 0.0;
        $totalOutstandingAmount = 0.0;

        $overdueCount = 0;
        $dueSoonCount = 0;

        foreach ($invoices as $invoice) {
            $daysUntilDue = (int) Carbon::today()->diffInDays($invoice->due_date, false);
            $remaining = (float) $invoice->remaining_balance;
            $totalOutstandingAmount += $remaining;

            if ($daysUntilDue < 0) {
                if ($invoice->status !== 'overdue') {
                    $invoice->update(['status' => 'overdue']);
                }
                $urgency = 'overdue';
                $urgencyLabel = 'Lewat Tempo ('.abs($daysUntilDue).' hari lalu)';
                $totalOverdueAmount += $remaining;
                $overdueCount++;
            } else {
                $urgency = 'due_soon';
                $urgencyLabel = $daysUntilDue === 0 ? 'Jatuh Tempo Hari Ini' : ('Jatuh Tempo Dalam '.$daysUntilDue.' Hari');
                $totalDueSoonAmount += $remaining;
                $dueSoonCount++;
            }

            $alerts[] = [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier?->name,
                'supplier_code' => $invoice->supplier?->code,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => (float) $invoice->paid_amount,
                'remaining_balance' => $remaining,
                'days_until_due' => $daysUntilDue,
                'urgency' => $urgency,
                'urgency_label' => $urgencyLabel,
                'status' => $invoice->status,
            ];
        }

        return [
            'summary' => [
                'days_threshold' => $daysThreshold,
                'total_invoices_alert' => count($alerts),
                'total_outstanding_amount' => round($totalOutstandingAmount, 2),
                'total_overdue_amount' => round($totalOverdueAmount, 2),
                'total_due_soon_amount' => round($totalDueSoonAmount, 2),
                'breakdown' => [
                    'overdue_count' => $overdueCount,
                    'due_soon_count' => $dueSoonCount,
                ],
            ],
            'invoices' => $alerts,
        ];
    }
}
