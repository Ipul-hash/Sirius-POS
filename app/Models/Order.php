<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'retail_subtotal' => 'decimal:2',
            'coffee_subtotal' => 'decimal:2',
            'retail_cogs' => 'decimal:2',
            'coffee_cogs' => 'decimal:2',
            'is_void' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function kdsTicket(): HasOne
    {
        return $this->hasOne(KdsTicket::class);
    }

    public function hasCoffeeItems(): bool
    {
        return $this->items()->where('division', 'coffee')->exists();
    }

    public function getGrossProfitRetailAttribute(): float
    {
        return round((float) $this->retail_subtotal - (float) $this->retail_cogs, 2);
    }

    public function getGrossProfitCoffeeAttribute(): float
    {
        return round((float) $this->coffee_subtotal - (float) $this->coffee_cogs, 2);
    }

    public function getTotalGrossProfitAttribute(): float
    {
        return round($this->gross_profit_retail + $this->gross_profit_coffee, 2);
    }
}
