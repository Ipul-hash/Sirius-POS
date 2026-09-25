<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentSettlementItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initial_stock_qty' => 'integer',
            'received_qty' => 'integer',
            'sold_qty' => 'integer',
            'returned_damaged_qty' => 'integer',
            'closing_stock_qty' => 'integer',
            'selling_price' => 'decimal:2',
            'gross_sales' => 'decimal:2',
            'store_commission_pct' => 'decimal:2',
            'store_commission_amount' => 'decimal:2',
            'vendor_payable_amount' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ConsignmentSettlement::class, 'settlement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
