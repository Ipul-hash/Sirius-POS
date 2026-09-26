<?php

namespace App\Services;

use App\Models\InternalStockTransfer;
use App\Models\InternalStockTransferItem;
use App\Models\InventoryBatch;
use App\Models\InventoryWasteLog;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transferStock(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $fromLocation = Location::findOrFail($data['from_location_id']);
            $toLocation = Location::findOrFail($data['to_location_id']);

            $creatorId = $userId
                ?? auth()->id()
                ?? User::where('role', 'cashier')->first()?->id
                ?? 1;

            $seq = (int) InternalStockTransfer::max('id') + 1;
            do {
                $transferNumber = 'TRF-'.Carbon::today()->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $seq++;
            } while (InternalStockTransfer::where('transfer_number', $transferNumber)->exists());

            $transfer = InternalStockTransfer::create([
                'transfer_number' => $transferNumber,
                'from_location_id' => $fromLocation->id,
                'to_location_id' => $toLocation->id,
                'requested_by' => $creatorId,
                'approved_by' => $creatorId,
                'status' => 'completed',
                'transfer_date' => Carbon::now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $transferredItems = [];
            $stockMovements = [];
            $totalTransferValue = 0.0;

            foreach ($data['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $quantity = (float) $itemData['quantity'];
                $product = Product::findOrFail($productId);

                $batches = InventoryBatch::where('product_id', $productId)
                    ->where('location_id', $fromLocation->id)
                    ->active()
                    ->fefo()
                    ->get();

                $availableQty = (float) $batches->sum('current_qty');
                if ($availableQty < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->name} di {$fromLocation->name} tidak mencukupi (Tersedia: {$availableQty}, Diminta: {$quantity}).",
                    ]);
                }

                $remainingQty = $quantity;

                foreach ($batches as $sourceBatch) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $currentSourceQty = (float) $sourceBatch->current_qty;
                    $deductQty = min($remainingQty, $currentSourceQty);
                    $newSourceQty = round($currentSourceQty - $deductQty, 4);

                    $sourceBatch->update([
                        'current_qty' => $newSourceQty,
                        'status' => $newSourceQty <= 0 ? 'depleted' : 'active',
                    ]);

                    $destinationBatch = InventoryBatch::where('product_id', $productId)
                        ->where('location_id', $toLocation->id)
                        ->where('batch_no', $sourceBatch->batch_no)
                        ->first();

                    if ($destinationBatch) {
                        $destinationBatch->update([
                            'current_qty' => round((float) $destinationBatch->current_qty + $deductQty, 4),
                            'initial_qty' => round((float) $destinationBatch->initial_qty + $deductQty, 4),
                            'status' => 'active',
                        ]);
                    } else {
                        $destinationBatch = InventoryBatch::create([
                            'batch_no' => $sourceBatch->batch_no,
                            'product_id' => $productId,
                            'location_id' => $toLocation->id,
                            'initial_qty' => $deductQty,
                            'current_qty' => $deductQty,
                            'unit_cost' => $sourceBatch->unit_cost,
                            'expired_at' => $sourceBatch->expired_at,
                            'status' => 'active',
                        ]);
                    }

                    $unitCost = (float) $sourceBatch->unit_cost;
                    $lineCost = round($deductQty * $unitCost, 2);
                    $totalTransferValue += $lineCost;

                    $transferItem = InternalStockTransferItem::create([
                        'transfer_id' => $transfer->id,
                        'product_id' => $productId,
                        'batch_id' => $sourceBatch->id,
                        'requested_qty' => $deductQty,
                        'transferred_qty' => $deductQty,
                        'unit_id' => $product->primary_unit_id,
                        'unit_cost' => $unitCost,
                    ]);

                    $movement = StockMovement::create([
                        'branch_id' => $fromLocation->branch_id,
                        'product_id' => $productId,
                        'batch_id' => $sourceBatch->id,
                        'from_location_id' => $fromLocation->id,
                        'to_location_id' => $toLocation->id,
                        'reference_type' => 'INTERNAL_TRANSFER',
                        'reference_id' => $transfer->id,
                        'quantity' => $deductQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => $lineCost,
                        'notes' => "Transfer {$product->name} x{$deductQty} dari {$fromLocation->code} ke {$toLocation->code}",
                        'created_by' => $creatorId,
                        'created_at' => Carbon::now(),
                    ]);

                    $transferredItems[] = [
                        'id' => $transferItem->id,
                        'product_id' => $productId,
                        'product_name' => $product->name,
                        'batch_no' => $sourceBatch->batch_no,
                        'expired_at' => $sourceBatch->expired_at?->toDateString(),
                        'quantity' => $deductQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => $lineCost,
                    ];

                    $stockMovements[] = [
                        'id' => $movement->id,
                        'reference_type' => $movement->reference_type,
                        'reference_id' => $movement->reference_id,
                        'quantity' => (float) $movement->quantity,
                        'total_cost' => (float) $movement->total_cost,
                    ];

                    $remainingQty = round($remainingQty - $deductQty, 4);
                }
            }

            return [
                'transfer' => [
                    'id' => $transfer->id,
                    'transfer_number' => $transfer->transfer_number,
                    'status' => $transfer->status,
                    'from_location' => [
                        'id' => $fromLocation->id,
                        'code' => $fromLocation->code,
                        'name' => $fromLocation->name,
                        'division' => $fromLocation->division,
                    ],
                    'to_location' => [
                        'id' => $toLocation->id,
                        'code' => $toLocation->code,
                        'name' => $toLocation->name,
                        'division' => $toLocation->division,
                    ],
                    'transfer_date' => $transfer->transfer_date->toISOString(),
                    'notes' => $transfer->notes,
                    'total_transfer_value' => round($totalTransferValue, 2),
                ],
                'items' => $transferredItems,
                'movements' => $stockMovements,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getExpiryAlerts(array $filters = []): array
    {
        $daysThreshold = (int) ($filters['days_threshold'] ?? 60);
        $branchId = (int) ($filters['branch_id'] ?? 1);
        $locationId = isset($filters['location_id']) ? (int) $filters['location_id'] : null;

        $targetDate = Carbon::today()->addDays($daysThreshold);

        $query = InventoryBatch::with(['product', 'location'])
            ->active()
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', $targetDate)
            ->whereHas('location', function ($q) use ($branchId, $locationId) {
                $q->where('branch_id', $branchId);
                if ($locationId) {
                    $q->where('id', $locationId);
                }
            })
            ->orderBy('expired_at', 'asc');

        $batches = $query->get();

        $alerts = [];
        $totalValueAtRisk = 0.0;
        $expiredCount = 0;
        $criticalCount = 0;
        $warningCount = 0;
        $attentionCount = 0;

        foreach ($batches as $batch) {
            $daysUntilExpiry = (int) Carbon::today()->diffInDays($batch->expired_at, false);
            $currentQty = (float) $batch->current_qty;
            $unitCost = (float) $batch->unit_cost;
            $batchValue = round($currentQty * $unitCost, 2);
            $totalValueAtRisk += $batchValue;

            if ($daysUntilExpiry < 0) {
                $urgency = 'expired';
                $urgencyLabel = 'Sudah Kedaluwarsa ('.abs($daysUntilExpiry).' hari lalu)';
                $expiredCount++;
            } elseif ($daysUntilExpiry <= 7) {
                $urgency = 'critical';
                $urgencyLabel = 'Kritis (H-'.$daysUntilExpiry.')';
                $criticalCount++;
            } elseif ($daysUntilExpiry <= 30) {
                $urgency = 'warning';
                $urgencyLabel = 'Peringatan (H-'.$daysUntilExpiry.')';
                $warningCount++;
            } else {
                $urgency = 'attention';
                $urgencyLabel = 'Perhatian (H-'.$daysUntilExpiry.')';
                $attentionCount++;
            }

            $alerts[] = [
                'batch_id' => $batch->id,
                'batch_no' => $batch->batch_no,
                'product_id' => $batch->product_id,
                'product_name' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'division' => $batch->product?->division,
                'location' => [
                    'id' => $batch->location_id,
                    'code' => $batch->location?->code,
                    'name' => $batch->location?->name,
                    'division' => $batch->location?->division,
                ],
                'remaining_stock' => $currentQty,
                'unit_cost' => $unitCost,
                'total_value' => $batchValue,
                'expired_at' => $batch->expired_at?->toDateString(),
                'days_until_expiry' => $daysUntilExpiry,
                'urgency' => $urgency,
                'urgency_label' => $urgencyLabel,
            ];
        }

        return [
            'summary' => [
                'days_threshold' => $daysThreshold,
                'total_batches_at_risk' => count($alerts),
                'total_value_at_risk' => round($totalValueAtRisk, 2),
                'breakdown' => [
                    'expired' => $expiredCount,
                    'critical_h7' => $criticalCount,
                    'warning_h30' => $warningCount,
                    'attention_h60' => $attentionCount,
                ],
            ],
            'alerts' => $alerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordWaste(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $location = Location::findOrFail($data['location_id']);
            $product = Product::findOrFail($data['product_id']);
            $quantity = (float) $data['quantity'];
            $reason = $data['reason'];

            $recorderId = $userId
                ?? auth()->id()
                ?? User::where('role', 'barista')->first()?->id
                ?? 1;

            $division = ($location->division === 'coffee' || $product->division === 'coffee') ? 'coffee' : 'retail';

            $batchId = $data['batch_id'] ?? null;
            $totalLoss = 0.0;
            $primaryBatch = null;

            if ($batchId) {
                $batch = InventoryBatch::where('id', $batchId)->where('location_id', $location->id)->firstOrFail();
                $primaryBatch = $batch;
                $unitCost = (float) $batch->unit_cost;
                $totalLoss = round($quantity * $unitCost, 2);

                $newQty = round((float) $batch->current_qty - $quantity, 4);
                $batch->update([
                    'current_qty' => max(0, $newQty),
                    'status' => $newQty <= 0 ? 'depleted' : 'active',
                ]);
            } else {
                $batches = InventoryBatch::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->active()
                    ->fefo()
                    ->get();

                $remainingQty = $quantity;
                $firstBatch = null;

                foreach ($batches as $batch) {
                    if ($remainingQty <= 0) {
                        break;
                    }
                    if (! $firstBatch) {
                        $firstBatch = $batch;
                    }

                    $curQty = (float) $batch->current_qty;
                    $deduct = min($remainingQty, $curQty);
                    $newQty = round($curQty - $deduct, 4);

                    $batch->update([
                        'current_qty' => $newQty,
                        'status' => $newQty <= 0 ? 'depleted' : 'active',
                    ]);

                    $lineLoss = round($deduct * (float) $batch->unit_cost, 2);
                    $totalLoss += $lineLoss;
                    $remainingQty = round($remainingQty - $deduct, 4);
                }

                if ($remainingQty > 0) {
                    $fallbackUnitCost = (float) $product->purchase_price;
                    $totalLoss += round($remainingQty * $fallbackUnitCost, 2);
                }

                $primaryBatch = $firstBatch;
            }

            $effectiveUnitCost = $quantity > 0 ? round($totalLoss / $quantity, 2) : (float) $product->purchase_price;

            $wasteLog = InventoryWasteLog::create([
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'batch_id' => $primaryBatch?->id,
                'division' => $division,
                'quantity' => $quantity,
                'unit_id' => $product->primary_unit_id,
                'unit_cost' => $effectiveUnitCost,
                'total_loss' => $totalLoss,
                'reason' => $reason,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $recorderId,
                'approved_by' => $recorderId,
                'created_at' => Carbon::now(),
            ]);

            StockMovement::create([
                'branch_id' => $location->branch_id,
                'product_id' => $product->id,
                'batch_id' => $primaryBatch?->id,
                'from_location_id' => $location->id,
                'to_location_id' => null,
                'reference_type' => 'WASTE_SPOILAGE',
                'reference_id' => $wasteLog->id,
                'quantity' => $quantity,
                'unit_cost' => $effectiveUnitCost,
                'total_cost' => $totalLoss,
                'notes' => "Pencatatan Waste ({$reason}): ".($data['notes'] ?? ''),
                'created_by' => $recorderId,
                'created_at' => Carbon::now(),
            ]);

            $remainingLocationStock = (float) InventoryBatch::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->active()
                ->sum('current_qty');

            return [
                'waste_log' => [
                    'id' => $wasteLog->id,
                    'branch_id' => $wasteLog->branch_id,
                    'location_id' => $wasteLog->location_id,
                    'location_name' => $location->name,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'division' => $wasteLog->division,
                    'quantity' => (float) $wasteLog->quantity,
                    'unit_id' => $wasteLog->unit_id,
                    'unit_cost' => (float) $wasteLog->unit_cost,
                    'total_loss' => (float) $wasteLog->total_loss,
                    'reason' => $wasteLog->reason,
                    'notes' => $wasteLog->notes,
                    'created_at' => $wasteLog->created_at->toISOString(),
                ],
                'remaining_stock' => $remainingLocationStock,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function performStockOpname(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $location = Location::findOrFail($data['location_id']);
            $categoryId = $data['category_id'] ?? null;

            $initiatorId = $userId
                ?? auth()->id()
                ?? User::where('role', 'supervisor')->first()?->id
                ?? 1;

            $seq = (int) StockOpname::max('id') + 1;
            do {
                $opnameNumber = 'OPN-'.Carbon::today()->format('Ymd').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $seq++;
            } while (StockOpname::where('opname_number', $opnameNumber)->exists());

            $opname = StockOpname::create([
                'opname_number' => $opnameNumber,
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'category_id' => $categoryId,
                'status' => 'approved',
                'opname_date' => Carbon::today(),
                'notes' => $data['notes'] ?? null,
                'initiated_by' => $initiatorId,
                'approved_by' => $initiatorId,
                'approved_at' => Carbon::now(),
            ]);

            $itemsResponse = [];
            $totalItemsCount = count($data['items']);
            $totalDiscrepancyQty = 0.0;
            $totalDiscrepancyValue = 0.0;
            $surplusCount = 0;
            $deficitCount = 0;
            $matchCount = 0;

            foreach ($data['items'] as $itemInput) {
                $productId = (int) $itemInput['product_id'];
                $physicalQty = (float) $itemInput['physical_qty'];
                $product = Product::findOrFail($productId);

                $systemQty = (float) InventoryBatch::where('product_id', $productId)
                    ->where('location_id', $location->id)
                    ->active()
                    ->sum('current_qty');

                $differenceQty = round($physicalQty - $systemQty, 4);

                $latestBatch = InventoryBatch::where('product_id', $productId)
                    ->where('location_id', $location->id)
                    ->latest()
                    ->first();

                $unitCost = (float) ($latestBatch?->unit_cost ?? $product->purchase_price);
                $differenceValue = round($differenceQty * $unitCost, 2);

                $opnameItem = StockOpnameItem::create([
                    'opname_id' => $opname->id,
                    'product_id' => $productId,
                    'batch_id' => $latestBatch?->id,
                    'system_qty' => $systemQty,
                    'physical_qty' => $physicalQty,
                    'difference_qty' => $differenceQty,
                    'unit_cost' => $unitCost,
                    'difference_value' => $differenceValue,
                    'notes' => $itemInput['notes'] ?? null,
                ]);

                if ($differenceQty < 0) {
                    $deficitCount++;
                    $remainingToDeduct = abs($differenceQty);

                    $batches = InventoryBatch::where('product_id', $productId)
                        ->where('location_id', $location->id)
                        ->active()
                        ->fefo()
                        ->get();

                    foreach ($batches as $batch) {
                        if ($remainingToDeduct <= 0) {
                            break;
                        }
                        $cQty = (float) $batch->current_qty;
                        $deduct = min($remainingToDeduct, $cQty);
                        $newQty = round($cQty - $deduct, 4);
                        $batch->update([
                            'current_qty' => $newQty,
                            'status' => $newQty <= 0 ? 'depleted' : 'active',
                        ]);
                        $remainingToDeduct = round($remainingToDeduct - $deduct, 4);
                    }

                    StockMovement::create([
                        'branch_id' => $location->branch_id,
                        'product_id' => $productId,
                        'batch_id' => $latestBatch?->id,
                        'from_location_id' => $location->id,
                        'to_location_id' => null,
                        'reference_type' => 'STOCK_ADJUSTMENT',
                        'reference_id' => $opname->id,
                        'quantity' => abs($differenceQty),
                        'unit_cost' => $unitCost,
                        'total_cost' => abs($differenceValue),
                        'notes' => "Stock Opname Defisit ({$differenceQty}): ".($itemInput['notes'] ?? ''),
                        'created_by' => $initiatorId,
                        'created_at' => Carbon::now(),
                    ]);
                } elseif ($differenceQty > 0) {
                    $surplusCount++;

                    if ($latestBatch) {
                        $latestBatch->update([
                            'current_qty' => round((float) $latestBatch->current_qty + $differenceQty, 4),
                            'initial_qty' => round((float) $latestBatch->initial_qty + $differenceQty, 4),
                            'status' => 'active',
                        ]);
                    } else {
                        InventoryBatch::create([
                            'batch_no' => 'B-ADJ-'.Carbon::today()->format('ymd').'-'.rand(100, 999),
                            'product_id' => $productId,
                            'location_id' => $location->id,
                            'initial_qty' => $differenceQty,
                            'current_qty' => $differenceQty,
                            'unit_cost' => $unitCost,
                            'expired_at' => Carbon::today()->addMonths(6),
                            'status' => 'active',
                        ]);
                    }

                    StockMovement::create([
                        'branch_id' => $location->branch_id,
                        'product_id' => $productId,
                        'batch_id' => $latestBatch?->id,
                        'from_location_id' => null,
                        'to_location_id' => $location->id,
                        'reference_type' => 'STOCK_ADJUSTMENT',
                        'reference_id' => $opname->id,
                        'quantity' => $differenceQty,
                        'unit_cost' => $unitCost,
                        'total_cost' => $differenceValue,
                        'notes' => "Stock Opname Surplus (+{$differenceQty}): ".($itemInput['notes'] ?? ''),
                        'created_by' => $initiatorId,
                        'created_at' => Carbon::now(),
                    ]);
                } else {
                    $matchCount++;
                }

                $totalDiscrepancyQty += $differenceQty;
                $totalDiscrepancyValue += $differenceValue;

                $itemsResponse[] = [
                    'id' => $opnameItem->id,
                    'product_id' => $productId,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'system_qty' => $systemQty,
                    'physical_qty' => $physicalQty,
                    'difference_qty' => $differenceQty,
                    'unit_cost' => $unitCost,
                    'difference_value' => $differenceValue,
                    'status' => $differenceQty === 0.0 ? 'matched' : ($differenceQty > 0 ? 'surplus' : 'deficit'),
                    'notes' => $opnameItem->notes,
                ];
            }

            return [
                'stock_opname' => [
                    'id' => $opname->id,
                    'opname_number' => $opname->opname_number,
                    'location_id' => $location->id,
                    'location_name' => $location->name,
                    'opname_date' => $opname->opname_date->toDateString(),
                    'status' => $opname->status,
                    'approved_at' => $opname->approved_at?->toISOString(),
                    'summary' => [
                        'total_products_checked' => $totalItemsCount,
                        'matched_count' => $matchCount,
                        'deficit_count' => $deficitCount,
                        'surplus_count' => $surplusCount,
                        'total_difference_qty' => round($totalDiscrepancyQty, 4),
                        'total_difference_value' => round($totalDiscrepancyValue, 2),
                    ],
                ],
                'items' => $itemsResponse,
            ];
        });
    }
}
