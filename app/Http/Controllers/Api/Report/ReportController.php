<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\DailyPnlRequest;
use App\Services\ReportService;
use Exception;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    public function dailyPnl(DailyPnlRequest $request): JsonResponse
    {
        try {
            $data = $this->reportService->getDailyPnl($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Laporan laba kotor harian per divisi berhasil dimuat.',
                'data' => $data,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
