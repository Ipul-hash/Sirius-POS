<?php

namespace App\Services;

use App\Models\ConsignmentSettlement;
use App\Models\ConsignmentSettlementItem;
use App\Models\InventoryBatch;
use App\Models\InventoryWasteLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConsignmentService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function calculateAndCreateSettlement(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $branchId = (int) ($data['branch_id'] ?? 1);
            $periodStart = $data['period_start'];
            $periodEnd = $data['period_end'];

            $creatorId = $userId
                ?? auth()->id()
                ?? User::where('role', 'admin')->first()?->id
                ?? 1;

            $seq = (int) ConsignmentSettlement::max('id') + 1;
            do {
                $settlementNo = 'SETTLE-'.Carbon::today()->format('Ym').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $seq++;
            } while (ConsignmentSettlement::where('settlement_no', $settlementNo)->exists());

            $storeCommissionPct = (float) ($supplier->revenue_share_percentage ?? 20.00);

            $rawItems = $data['items'] ?? null;
            $itemsToProcess = [];

            if (! empty($rawItems)) {
                foreach ($rawItems as $itemInput) {
                    $product = Product::findOrFail($itemInput['product_id']);
                    $soldQty = (int) ($itemInput['sold_qty'] ?? 0);
                    $returnedQty = (int) ($itemInput['returned_damaged_qty'] ?? 0);
                    $receivedQty = (int) ($itemInput['received_qty'] ?? ($soldQty + $returnedQty));
                    $initialStockQty = (int) ($itemInput['initial_stock_qty'] ?? 0);
                    $closingStockQty = (int) ($itemInput['closing_stock_qty'] ?? max(0, $initialStockQty + $receivedQty - $soldQty - $returnedQty));

                    $sellingPrice = (float) $product->selling_price;
                    $grossSales = round($soldQty * $sellingPrice, 2);
                    $storeCommissionAmount = round($grossSales * ($storeCommissionPct / 100), 2);
                    $vendorPayableAmount = round($grossSales - $storeCommissionAmount, 2);

                    $itemsToProcess[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'initial_stock_qty' => $initialStockQty,
                        'received_qty' => $receivedQty,
                        'sold_qty' => $soldQty,
                        'returned_damaged_qty' => $returnedQty,
                        'closing_stock_qty' => $closingStockQty,
                        'selling_price' => $sellingPrice,
                        'gross_sales' => $grossSales,
                        'store_commission_pct' => $storeCommissionPct,
                        'store_commission_amount' => $storeCommissionAmount,
                        'vendor_payable_amount' => $vendorPayableAmount,
                    ];
                }
            } else {
                $products = Product::where('supplier_id', $supplier->id)->get();

                foreach ($products as $product) {
                    $soldQty = (int) OrderItem::where('product_id', $product->id)
                        ->whereHas('order', function ($q) use ($branchId, $periodStart, $periodEnd) {
                            $q->where('branch_id', $branchId)
                                ->whereBetween('order_date', [
                                    Carbon::parse($periodStart)->startOfDay(),
                                    Carbon::parse($periodEnd)->endOfDay(),
                                ])
                                ->where('is_void', false);
                        })
                        ->sum('quantity');

                    $returnedQty = (int) InventoryWasteLog::where('product_id', $product->id)
                        ->where('branch_id', $branchId)
                        ->whereBetween('created_at', [
                            Carbon::parse($periodStart)->startOfDay(),
                            Carbon::parse($periodEnd)->endOfDay(),
                        ])
                        ->sum('quantity');

                    $closingStockQty = (int) InventoryBatch::where('product_id', $product->id)
                        ->whereHas('location', fn ($q) => $q->where('branch_id', $branchId))
                        ->active()
                        ->sum('current_qty');

                    $receivedQty = $soldQty + $returnedQty;
                    $initialStockQty = $closingStockQty;

                    $sellingPrice = (float) $product->selling_price;
                    $grossSales = round($soldQty * $sellingPrice, 2);
                    $storeCommissionAmount = round($grossSales * ($storeCommissionPct / 100), 2);
                    $vendorPayableAmount = round($grossSales - $storeCommissionAmount, 2);

                    $itemsToProcess[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'initial_stock_qty' => $initialStockQty,
                        'received_qty' => $receivedQty,
                        'sold_qty' => $soldQty,
                        'returned_damaged_qty' => $returnedQty,
                        'closing_stock_qty' => $closingStockQty,
                        'selling_price' => $sellingPrice,
                        'gross_sales' => $grossSales,
                        'store_commission_pct' => $storeCommissionPct,
                        'store_commission_amount' => $storeCommissionAmount,
                        'vendor_payable_amount' => $vendorPayableAmount,
                    ];
                }
            }

            $totalSoldQty = array_sum(array_column($itemsToProcess, 'sold_qty'));
            $totalGrossSales = round(array_sum(array_column($itemsToProcess, 'gross_sales')), 2);
            $totalReturnedQty = array_sum(array_column($itemsToProcess, 'returned_damaged_qty'));
            $storeShareAmount = round(array_sum(array_column($itemsToProcess, 'store_commission_amount')), 2);
            $vendorPayableAmount = round(array_sum(array_column($itemsToProcess, 'vendor_payable_amount')), 2);

            $settlement = ConsignmentSettlement::create([
                'settlement_no' => $settlementNo,
                'supplier_id' => $supplier->id,
                'branch_id' => $branchId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'total_sold_qty' => $totalSoldQty,
                'total_gross_sales' => $totalGrossSales,
                'total_returned_qty' => $totalReturnedQty,
                'store_share_amount' => $storeShareAmount,
                'vendor_payable_amount' => $vendorPayableAmount,
                'status' => $data['status'] ?? 'approved',
                'settled_by' => $creatorId,
                'notes' => $data['notes'] ?? null,
            ]);

            $savedItems = [];
            foreach ($itemsToProcess as $item) {
                $savedItem = ConsignmentSettlementItem::create([
                    'settlement_id' => $settlement->id,
                    'product_id' => $item['product_id'],
                    'initial_stock_qty' => $item['initial_stock_qty'],
                    'received_qty' => $item['received_qty'],
                    'sold_qty' => $item['sold_qty'],
                    'returned_damaged_qty' => $item['returned_damaged_qty'],
                    'closing_stock_qty' => $item['closing_stock_qty'],
                    'selling_price' => $item['selling_price'],
                    'gross_sales' => $item['gross_sales'],
                    'store_commission_pct' => $item['store_commission_pct'],
                    'store_commission_amount' => $item['store_commission_amount'],
                    'vendor_payable_amount' => $item['vendor_payable_amount'],
                ]);

                $savedItems[] = array_merge(['id' => $savedItem->id], $item);
            }

            return [
                'settlement' => [
                    'id' => $settlement->id,
                    'settlement_no' => $settlement->settlement_no,
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'contact_person' => $supplier->contact_person,
                    'branch_id' => $branchId,
                    'period_start' => $settlement->period_start?->toDateString(),
                    'period_end' => $settlement->period_end?->toDateString(),
                    'total_sold_qty' => $settlement->total_sold_qty,
                    'total_gross_sales' => (float) $settlement->total_gross_sales,
                    'total_returned_qty' => $settlement->total_returned_qty,
                    'store_commission_pct' => $storeCommissionPct,
                    'store_share_amount' => (float) $settlement->store_share_amount,
                    'vendor_payable_amount' => (float) $settlement->vendor_payable_amount,
                    'status' => $settlement->status,
                    'notes' => $settlement->notes,
                ],
                'items' => $savedItems,
            ];
        });
    }
}
