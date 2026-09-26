<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ApAlertsRequest;
use App\Http\Requests\Purchasing\CreatePurchaseOrderRequest;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Services\PurchasingService;
use Exception;
use Illuminate\Http\JsonResponse;

class PurchasingController extends Controller
{
    public function __construct(
        protected PurchasingService $purchasingService
    ) {}

    public function createOrder(CreatePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $purchaseOrder = $this->purchasingService->createPurchaseOrder(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Purchase order berhasil diterbitkan.',
                'data' => $purchaseOrder,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function goodsReceipt(GoodsReceiptRequest $request): JsonResponse
    {
        try {
            $result = $this->purchasingService->receiveGoods(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Penerimaan barang berhasil diverifikasi dan faktur hutang telah diterbitkan.',
                'data' => $result,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function apAlerts(ApAlertsRequest $request): JsonResponse
    {
        try {
            $result = $this->purchasingService->getApAlerts($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Alert jatuh tempo hutang usaha (AP Aging) berhasil dimuat.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
