<?php

namespace App\Services;

use App\Models\KdsTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class KdsService
{
    /**
     * Mengambil daftar tiket antrean KDS untuk display barista.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, KdsTicket>
     */
    public function getTickets(array $filters = []): Collection
    {
        $branchId = (int) ($filters['branch_id'] ?? 1);
        $status = isset($filters['status']) ? trim($filters['status']) : 'active';

        $query = KdsTicket::with([
            'order.customer',
            'order.cashier',
            'branch',
            'barista',
            'items.product',
        ])->where('branch_id', $branchId);

        // Filter status tiket
        if ($status === 'active' || empty($status)) {
            $query->active(); // queued, preparing, ready
        } elseif ($status === 'all') {
            // Tampilkan semua riwayat
        } elseif (str_contains($status, ',')) {
            $statuses = array_filter(array_map('trim', explode(',', $status)));
            $query->whereIn('status', $statuses);
        } else {
            $query->where('status', $status);
        }

        // Filter tanggal jika ditentukan (format Y-m-d)
        if (! empty($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        // FIFO: Berurutan berdasarkan waktu order kasir yang paling awal masuk
        return $query->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Mengambil detail satu tiket KDS berdasarkan ID.
     */
    public function getTicketById(int $ticketId): KdsTicket
    {
        return KdsTicket::with([
            'order.customer',
            'order.cashier',
            'branch',
            'barista',
            'items.product',
        ])->findOrFail($ticketId);
    }

    /**
     * Memperbarui status antrean KDS (state machine lifecycle).
     *
     * Alur status:
     * - queued    : Tiket baru dibuat dari Kasir POS.
     * - preparing : Barista menekan "Start", mencatat started_at & barista yang meracik.
     * - ready     : Minuman selesai diracik, siap di meja pickup (ready_at).
     * - collected : Minuman diserahkan ke pelanggan dengan verifikasi struk (collected_at).
     */
    public function updateStatus(int $ticketId, string $status, ?int $baristaId = null, ?string $notes = null): KdsTicket
    {
        $normalizedStatus = strtolower(trim($status));

        // Alias penanganan jika UI mengirim 'start'
        if ($normalizedStatus === 'start') {
            $normalizedStatus = 'preparing';
        }

        $ticket = $this->getTicketById($ticketId);

        if ($notes !== null) {
            $ticket->notes = $notes;
            $ticket->save();
        }

        switch ($normalizedStatus) {
            case 'preparing':
                $assignedBaristaId = $baristaId
                    ?? $ticket->barista_id
                    ?? User::where('role', 'barista')->first()?->id;

                $ticket->markAsPreparing($assignedBaristaId);
                break;

            case 'ready':
                $ticket->markAsReady();
                break;

            case 'collected':
                $ticket->markAsCollected();
                break;

            case 'queued':
                $ticket->markAsQueued();
                break;
        }

        return $ticket->refresh()->load([
            'order.customer',
            'order.cashier',
            'branch',
            'barista',
            'items.product',
        ]);
    }
}
