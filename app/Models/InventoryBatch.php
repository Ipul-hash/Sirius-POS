<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expired_at' => 'date',
            'initial_qty' => 'decimal:4',
            'current_qty' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /**
     * Scope only active batches with remaining quantity.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('current_qty', '>', 0);
    }

    /**
     * Order by First Expired, First Out (FEFO).
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN expired_at IS NULL THEN 1 ELSE 0 END, expired_at ASC, id ASC');
    }
}
