<?php

namespace App\Http\Controllers\Api\Kds;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kds\GetKdsTicketsRequest;
use App\Http\Requests\Kds\UpdateKdsTicketStatusRequest;
use App\Http\Resources\Kds\KdsTicketResource;
use App\Services\KdsService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class KdsController extends Controller
{
    public function __construct(
        protected KdsService $kdsService
    ) {}

    /**
     * API 3.1: Daftar Tiket Antrean Barista (KDS)
     *
     * Menampilkan daftar tiket antrean aktif (FIFO) pada tablet barista meja bar.
     */
    public function index(GetKdsTicketsRequest $request): JsonResponse
    {
        try {
            $tickets = $this->kdsService->getTickets($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Daftar tiket antrean KDS berhasil dimuat.',
                'count' => $tickets->count(),
                'data' => KdsTicketResource::collection($tickets),
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat tiket antrean KDS: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mengambil detail satu tiket KDS berdasarkan ID.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $ticket = $this->kdsService->getTicketById($id);

            return response()->json([
                'success' => true,
                'data' => new KdsTicketResource($ticket),
            ], 200);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => "Tiket KDS dengan ID {$id} tidak ditemukan.",
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * API 3.2: Update Status Antrean KDS
     *
     * Barista memperbarui siklus pesanan:
     * - Start (status: preparing) -> Mulai racik kopi
     * - Ready (status: ready) -> Kopi selesai di meja pickup, panggil nomor antrean
     * - Collected (status: collected) -> Kopi diambil pelanggan
     */
    public function updateStatus(UpdateKdsTicketStatusRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $baristaId = $validated['barista_id'] ?? $request->user()?->id;

            $ticket = $this->kdsService->updateStatus(
                ticketId: $id,
                status: $validated['status'],
                baristaId: $baristaId,
                notes: $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => "Status tiket antrean {$ticket->queue_number} berhasil diubah menjadi '{$ticket->status}'.",
                'data' => new KdsTicketResource($ticket),
            ], 200);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => "Tiket KDS dengan ID {$id} tidak ditemukan.",
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui status tiket KDS: '.$e->getMessage(),
            ], 422);
        }
    }
}
