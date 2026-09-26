<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Services\PosCheckoutService;
use Exception;
use Illuminate\Http\JsonResponse;

class PosCheckoutController extends Controller
{
    public function __construct(
        protected PosCheckoutService $checkoutService
    ) {}

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutService->checkout($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Transaksi pembayaran berhasil diproses.',
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
