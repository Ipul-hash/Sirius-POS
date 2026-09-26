<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ExpiryAlertsRequest;
use App\Http\Requests\Inventory\RecordWasteRequest;
use App\Http\Requests\Inventory\StockOpnameRequest;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Services\InventoryService;
use Exception;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function transfer(StockTransferRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->transferStock(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Transfer stok internal berhasil diproses.',
                'data' => $result,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function expiryAlerts(ExpiryAlertsRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->getExpiryAlerts($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Data alert kedaluwarsa FEFO berhasil dimuat.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function recordWaste(RecordWasteRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->recordWaste(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Pencatatan kerugian / waste log berhasil diproses.',
                'data' => $result,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function stockOpname(StockOpnameRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->performStockOpname(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock opname cycle count berhasil disetujui dan stok telah disesuaikan.',
                'data' => $result,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
