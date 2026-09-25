<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current_points' => 'integer',
            'total_spent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointsLedger::class);
    }

    /**
     * Add or redeem points and record ledger entry.
     */
    public function adjustPoints(int $points, string $type, string $division = 'general', ?int $orderId = null, ?string $description = null): void
    {
        $this->current_points += $points;
        $this->save();

        $this->loyaltyLedgers()->create([
            'order_id' => $orderId,
            'division' => $division,
            'type' => $type,
            'points' => $points,
            'description' => $description,
        ]);
    }
}
