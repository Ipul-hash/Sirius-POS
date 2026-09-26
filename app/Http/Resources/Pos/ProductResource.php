<?php

namespace App\Http\Resources\Pos;

use App\Models\InventoryBatch;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branchId = (int) ($request->input('branch_id', 1));

        // Available stock computation
        $availableStock = 0.0;
        if ($this->type === 'standard') {
            $retailLocation = Location::where('branch_id', $branchId)->where('code', 'RAK-FRONT')->first();
            $locationId = $retailLocation?->id;

            $query = InventoryBatch::where('product_id', $this->id)->active();
            if ($locationId) {
                $query->where('location_id', $locationId);
            }
            $availableStock = (float) $query->sum('current_qty');
        } elseif ($this->type === 'composite' && $this->boms->isNotEmpty()) {
            // Compute how many cups can be made from bar counter ingredients
            $coffeeLocation = Location::where('branch_id', $branchId)->where('code', 'BAR-COUNTER')->first();
            $maxPortions = PHP_FLOAT_MAX;

            foreach ($this->boms as $bom) {
                $yieldMultiplier = 1 + ((float) $bom->yield_loss_percentage / 100);
                $perPortionNeeded = (float) $bom->quantity * $yieldMultiplier;

                if ($perPortionNeeded <= 0) {
                    continue;
                }

                $query = InventoryBatch::where('product_id', $bom->material_product_id)->active();
                if ($coffeeLocation) {
                    $query->where('location_id', $coffeeLocation->id);
                }
                $materialStock = (float) $query->sum('current_qty');
                $portionsForMaterial = floor($materialStock / $perPortionNeeded);
                $maxPortions = min($maxPortions, $portionsForMaterial);
            }
            $availableStock = $maxPortions === PHP_FLOAT_MAX ? 0.0 : (float) $maxPortions;
        }

        // Customization options for coffee menu
        $customizations = null;
        if ($this->division === 'coffee' || $this->type === 'composite') {
            $customizations = [
                'sugar_levels' => ['Normal Sugar', 'Less Sugar', 'No Sugar', 'Extra Sugar'],
                'ice_levels' => ['Normal Ice', 'Less Ice', 'No Ice', 'Extra Ice'],
                'milk_options' => ['Fresh Milk', 'Oat Milk', 'Almond Milk', 'Soy Milk'],
            ];
        }

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'division' => $this->division,
            'type' => $this->type,
            'primary_unit' => $this->primaryUnit ? [
                'id' => $this->primaryUnit->id,
                'code' => $this->primaryUnit->code,
                'name' => $this->primaryUnit->name,
            ] : null,
            'selling_price' => (float) $this->selling_price,
            'purchase_price' => (float) $this->purchase_price,
            'min_stock_alert' => (int) $this->min_stock_alert,
            'available_stock' => $availableStock,
            'image_url' => null,
            'customizations' => $customizations,
            'price_tiers' => $this->priceTiers->map(fn ($tier) => [
                'id' => $tier->id,
                'min_qty' => (float) $tier->min_qty,
                'unit_price' => (float) $tier->unit_price,
            ]),
            'recipe_boms' => $this->boms->map(fn ($bom) => [
                'material_product_id' => $bom->material_product_id,
                'material_name' => $bom->materialProduct?->name,
                'material_sku' => $bom->materialProduct?->sku,
                'quantity' => (float) $bom->quantity,
                'unit' => $bom->unit?->code ?? 'pcs',
                'yield_loss_percentage' => (float) $bom->yield_loss_percentage,
            ]),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
