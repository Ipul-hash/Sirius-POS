<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OpenDrawerRequest;
use App\Http\Requests\Pos\VoidOrderRequest;
use App\Services\PosSecurityService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PosSecurityController extends Controller
{
    public function __construct(
        protected PosSecurityService $posSecurityService
    ) {}

    public function voidOrder(VoidOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->posSecurityService->voidOrder(
                data: $request->validated(),
                currentUserId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Order void berhasil, laci kas shift disesuaikan, log audit tersimpan.',
                'data' => $result,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function openDrawer(OpenDrawerRequest $request): JsonResponse
    {
        try {
            $result = $this->posSecurityService->openDrawer(
                data: $request->validated(),
                currentUserId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Audit log terekam dengan timestamp dan user kasir.',
                'data' => $result,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
