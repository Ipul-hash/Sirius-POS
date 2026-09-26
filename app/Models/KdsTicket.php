<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KdsTicket extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function barista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barista_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KdsTicketItem::class, 'ticket_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'preparing', 'ready']);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', 'queued');
    }

    public function scopePreparing(Builder $query): Builder
    {
        return $query->where('status', 'preparing');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    public function scopeCollected(Builder $query): Builder
    {
        return $query->where('status', 'collected');
    }

    public function markAsPreparing(?int $baristaId = null): void
    {
        $this->update([
            'status' => 'preparing',
            'started_at' => $this->started_at ?? now(),
            'barista_id' => $baristaId ?? $this->barista_id,
        ]);

        $this->items()->where('status', 'queued')->update(['status' => 'in_progress']);
    }

    public function markAsReady(): void
    {
        $this->update([
            'status' => 'ready',
            'ready_at' => now(),
        ]);

        $this->items()->where('status', '!=', 'done')->update(['status' => 'done']);
    }

    public function markAsCollected(): void
    {
        $this->update([
            'status' => 'collected',
            'collected_at' => now(),
        ]);

        $this->items()->where('status', '!=', 'done')->update(['status' => 'done']);
    }

    public function markAsQueued(): void
    {
        $this->update([
            'status' => 'queued',
        ]);

        $this->items()->update(['status' => 'queued']);
    }
}
