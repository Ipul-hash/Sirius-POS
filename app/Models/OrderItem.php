<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'is_void' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }

    public function kdsTicketItem(): HasOne
    {
        return $this->hasOne(KdsTicketItem::class);
    }

    public function isCoffee(): bool
    {
        return $this->division === 'coffee';
    }

    public function isRetail(): bool
    {
        return $this->division === 'retail';
    }
}
