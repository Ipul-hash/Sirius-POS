<?php

namespace App\Http\Controllers\Api\Consignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consignment\CreateSettlementRequest;
use App\Services\ConsignmentService;
use Exception;
use Illuminate\Http\JsonResponse;

class ConsignmentController extends Controller
{
    public function __construct(
        protected ConsignmentService $consignmentService
    ) {}

    public function settlement(CreateSettlementRequest $request): JsonResponse
    {
        try {
            $result = $this->consignmentService->calculateAndCreateSettlement(
                data: $request->validated(),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Settlement bagi hasil konsinyasi berhasil diterbitkan.',
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
