<?php

namespace App\Http\Resources\Kds;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KdsTicketItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'order_item_id' => $this->order_item_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name ?? 'Menu Kopi',
            'sku' => $this->product?->sku,
            'quantity' => (int) $this->quantity,
            'custom_notes' => $this->custom_notes,
            'status' => $this->status,
        ];
    }
}
