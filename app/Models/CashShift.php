<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'opening_balance' => 'decimal:2',
            'total_cash_sales' => 'decimal:2',
            'total_qris_sales' => 'decimal:2',
            'total_cash_out' => 'decimal:2',
            'expected_cash_in_drawer' => 'decimal:2',
            'actual_cash_in_drawer' => 'decimal:2',
            'discrepancy' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function pettyCashExpenses(): HasMany
    {
        return $this->hasMany(PettyCashExpense::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Recalculate shift totals and expected cash.
     */
    public function recalculateTotals(): void
    {
        $cashSales = (float) $this->orders()
            ->where('payment_status', 'paid')
            ->where('is_void', false)
            ->where('payment_method', 'cash')
            ->sum('total_amount');

        $qrisSales = (float) $this->orders()
            ->where('payment_status', 'paid')
            ->where('is_void', false)
            ->where('payment_method', 'qris')
            ->sum('total_amount');

        $cashOut = (float) $this->pettyCashExpenses()->sum('amount');

        $expected = (float) $this->opening_balance + $cashSales - $cashOut;

        $this->update([
            'total_cash_sales' => $cashSales,
            'total_qris_sales' => $qrisSales,
            'total_cash_out' => $cashOut,
            'expected_cash_in_drawer' => $expected,
        ]);
    }
}
