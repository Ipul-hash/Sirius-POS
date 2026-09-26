<?php

namespace App\Http\Resources\Kds;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KdsTicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $createdAt = $this->created_at ? Carbon::parse($this->created_at) : null;
        $startedAt = $this->started_at ? Carbon::parse($this->started_at) : null;
        $readyAt = $this->ready_at ? Carbon::parse($this->ready_at) : null;
        $collectedAt = $this->collected_at ? Carbon::parse($this->collected_at) : null;

        $elapsedSeconds = $createdAt ? (int) $createdAt->diffInSeconds(now()) : 0;
        $elapsedMinutes = $createdAt ? (int) floor($elapsedSeconds / 60) : 0;

        $prepTimeSeconds = null;
        if ($startedAt && $readyAt) {
            $prepTimeSeconds = (int) $startedAt->diffInSeconds($readyAt);
        } elseif ($startedAt) {
            $prepTimeSeconds = (int) $startedAt->diffInSeconds(now());
        }

        $statusLabels = [
            'queued' => 'Menunggu Antrean',
            'preparing' => 'Sedang Diracik',
            'ready' => 'Siap Diambil',
            'collected' => 'Sudah Diambil',
        ];

        return [
            'id' => $this->id,
            'queue_number' => $this->queue_number,
            'status' => $this->status,
            'status_label' => $statusLabels[$this->status] ?? ucfirst($this->status),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'notes' => $this->notes,
            'started_at' => $startedAt?->toISOString(),
            'ready_at' => $readyAt?->toISOString(),
            'collected_at' => $collectedAt?->toISOString(),
            'elapsed_seconds' => $elapsedSeconds,
            'elapsed_minutes' => $elapsedMinutes,
            'preparation_time_seconds' => $prepTimeSeconds,
            'created_at' => $createdAt?->toISOString(),
            'order' => $this->order ? [
                'id' => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'order_date' => $this->order->order_date?->toISOString() ?? $this->order->created_at?->toISOString(),
                'customer_name' => $this->order->customer?->name,
                'customer_phone' => $this->order->customer?->phone,
                'cashier_name' => $this->order->cashier?->name,
            ] : null,
            'barista' => $this->barista ? [
                'id' => $this->barista->id,
                'name' => $this->barista->name,
                'email' => $this->barista->email,
            ] : null,
            'items' => KdsTicketItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
