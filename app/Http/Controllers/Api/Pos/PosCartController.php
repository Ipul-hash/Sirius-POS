<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CalculateCartRequest;
use App\Services\CartCalculationService;
use Illuminate\Http\JsonResponse;

class PosCartController extends Controller
{
    public function __construct(
        protected CartCalculationService $cartCalculationService
    ) {}

    public function calculate(CalculateCartRequest $request): JsonResponse
    {
        $branchId = (int) $request->input('branch_id', 1);
        $items = $request->input('items', []);

        $result = $this->cartCalculationService->calculate($items, $branchId);

        return response()->json([
            'success' => true,
            'message' => 'Kalkulasi keranjang berhasil diproses.',
            'data' => $result,
        ]);
    }
}
