<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_stock_alert' => 'integer',
            'track_expiry' => 'boolean',
            'is_consignment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function primaryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'primary_unit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function uomConversions(): HasMany
    {
        return $this->hasMany(ProductUomConversion::class);
    }

    public function boms(): HasMany
    {
        return $this->hasMany(RecipeBom::class, 'parent_product_id');
    }

    public function parentBoms(): HasMany
    {
        return $this->hasMany(RecipeBom::class, 'material_product_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_qty', 'asc');
    }

    public function isComposite(): bool
    {
        return $this->type === 'composite';
    }

    public function isRawMaterial(): bool
    {
        return $this->type === 'raw_material';
    }

    public function isStandard(): bool
    {
        return $this->type === 'standard';
    }

    public function isCoffee(): bool
    {
        return $this->division === 'coffee';
    }

    public function isRetail(): bool
    {
        return $this->division === 'retail';
    }

    /**
     * Calculate current theoretical HPP from BOM recipe ingredients.
     */
    public function calculateBomCost(): float
    {
        if (! $this->isComposite()) {
            return (float) $this->purchase_price;
        }

        $totalCost = 0.0;
        foreach ($this->boms as $bom) {
            $material = $bom->materialProduct;
            if ($material) {
                $materialUnitCost = (float) $material->purchase_price;
                $ingredientCost = (float) $bom->quantity * $materialUnitCost * (1 + ((float) $bom->yield_loss_percentage / 100));
                $totalCost += $ingredientCost;
            }
        }

        return round($totalCost, 2);
    }

    /**
     * Get price for given quantity based on tier pricing if available.
     */
    public function getPriceForQuantity(float $quantity): float
    {
        $tiers = $this->priceTiers;
        $matchedPrice = (float) $this->selling_price;

        foreach ($tiers as $tier) {
            if ($quantity >= (float) $tier->min_qty) {
                $matchedPrice = (float) $tier->unit_price;
            }
        }

        return $matchedPrice;
    }
}
