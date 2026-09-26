<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;

class CartCalculationService
{
    /**
     * Calculate cart items, apply wholesale tier pricing and auto-bundling promotions.
     *
     * @param  array<int, array{product_id: int, quantity: float|int, notes?: string|null}>  $items
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     applied_promotions: array<int, array<string, mixed>>
     * }
     */
    public function calculate(array $items, int $branchId = 1): array
    {
        $calculatedItems = [];
        $appliedPromotions = [];

        // 1. Group quantities per product to support wholesale tier lookup and bundling evaluation
        $productTotals = [];
        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];
            $quantity = (float) $item['quantity'];

            if (! isset($productTotals[$productId])) {
                $productTotals[$productId] = 0.0;
            }
            $productTotals[$productId] += $quantity;
        }

        // 2. Fetch products with pricing tiers
        $productIds = array_keys($productTotals);
        $products = Product::with(['priceTiers', 'primaryUnit', 'category'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // 3. Process base line items & wholesale tier pricing
        foreach ($items as $index => $item) {
            $productId = (int) $item['product_id'];
            $quantity = (float) $item['quantity'];
            $notes = $item['notes'] ?? null;

            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $unitPrice = (float) $product->selling_price;
            $baseSubtotal = round($unitPrice * $quantity, 2);
            $tierDiscount = 0.0;
            $tierDiscountsBreakdown = [];

            // Check if product has wholesale tier pricing based on total purchased quantity
            $totalQtyForProduct = $productTotals[$productId];
            $matchedTier = null;

            foreach ($product->priceTiers as $tier) {
                if ($totalQtyForProduct >= (float) $tier->min_qty) {
                    $matchedTier = $tier;
                }
            }

            if ($matchedTier && (float) $matchedTier->unit_price < $unitPrice) {
                $discountPerUnit = $unitPrice - (float) $matchedTier->unit_price;
                $tierDiscount = round($discountPerUnit * $quantity, 2);
                $tierDiscountsBreakdown[] = [
                    'type' => 'tier_pricing',
                    'description' => "Harga Grosir Tier (>= {$matchedTier->min_qty} {$product->primaryUnit?->code})",
                    'discount_per_unit' => $discountPerUnit,
                    'amount' => $tierDiscount,
                ];
            }

            $calculatedItems[$index] = [
                'item_index' => $index,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'division' => $product->division,
                'type' => $product->type,
                'quantity' => $quantity,
                'unit_id' => $product->primary_unit_id,
                'unit_code' => $product->primaryUnit?->code ?? 'pcs',
                'unit_price' => $unitPrice,
                'base_subtotal' => $baseSubtotal,
                'tier_discount' => $tierDiscount,
                'promo_discount' => 0.0,
                'discount_amount' => $tierDiscount,
                'subtotal' => round($baseSubtotal - $tierDiscount, 2),
                'notes' => $notes,
                'discounts' => $tierDiscountsBreakdown,
            ];
        }

        // 4. Evaluate Active Promotions (Auto-Bundling, Cross-Bundle, Buy X Get Y)
        $activePromotions = Promotion::active()->with('rules.rewardProduct', 'rules.conditionProduct')->get();

        foreach ($activePromotions as $promo) {
            foreach ($promo->rules as $rule) {
                $conditionProductId = (int) $rule->condition_product_id;
                $rewardProductId = (int) $rule->reward_product_id;
                $conditionMinQty = (float) $rule->condition_min_qty;

                // Check condition product total quantity in cart
                $conditionQty = $productTotals[$conditionProductId] ?? 0.0;
                if ($conditionQty < $conditionMinQty) {
                    continue;
                }

                // Check reward product in cart
                $rewardQty = $productTotals[$rewardProductId] ?? 0.0;
                if ($rewardQty <= 0) {
                    continue;
                }

                // Calculate how many reward items are eligible for bundling discount
                $eligibleBundles = floor($conditionQty / $conditionMinQty);
                $eligibleRewardQty = min($rewardQty, (float) $eligibleBundles);

                if ($eligibleRewardQty <= 0) {
                    continue;
                }

                // Distribute reward discount across matching cart line items
                $remainingRewardDiscountQty = $eligibleRewardQty;

                foreach ($calculatedItems as $idx => &$lineItem) {
                    if ($lineItem['product_id'] !== $rewardProductId || $remainingRewardDiscountQty <= 0) {
                        continue;
                    }

                    $lineQty = (float) $lineItem['quantity'];
                    $qtyToDiscount = min($lineQty, $remainingRewardDiscountQty);

                    // Calculate discount value based on rule type
                    $unitPrice = (float) $lineItem['unit_price'];
                    $unitDiscount = 0.0;

                    if ($rule->reward_discount_type === 'percentage') {
                        $percent = (float) $rule->reward_discount_value;
                        $unitDiscount = round($unitPrice * ($percent / 100), 2);
                    } elseif ($rule->reward_discount_type === 'fixed_price') {
                        $targetPrice = (float) $rule->reward_discount_value;
                        $unitDiscount = max(0, round($unitPrice - $targetPrice, 2));
                    } elseif ($rule->reward_discount_type === 'free') {
                        $unitDiscount = $unitPrice;
                    }

                    $promoDiscountAmount = round($unitDiscount * $qtyToDiscount, 2);

                    if ($promoDiscountAmount > 0) {
                        $lineItem['promo_discount'] = round($lineItem['promo_discount'] + $promoDiscountAmount, 2);
                        $lineItem['discount_amount'] = round($lineItem['discount_amount'] + $promoDiscountAmount, 2);
                        $lineItem['subtotal'] = max(0, round($lineItem['base_subtotal'] - $lineItem['discount_amount'], 2));

                        $lineItem['discounts'][] = [
                            'type' => 'promotion',
                            'promo_id' => $promo->id,
                            'promo_name' => $promo->name,
                            'discount_type' => $rule->reward_discount_type,
                            'discount_value' => (float) $rule->reward_discount_value,
                            'discount_qty' => $qtyToDiscount,
                            'amount' => $promoDiscountAmount,
                        ];

                        $appliedPromotions[] = [
                            'promo_id' => $promo->id,
                            'promo_name' => $promo->name,
                            'type' => $promo->type,
                            'condition_product_id' => $conditionProductId,
                            'reward_product_id' => $rewardProductId,
                            'discount_amount' => $promoDiscountAmount,
                        ];

                        $remainingRewardDiscountQty -= $qtyToDiscount;
                    }
                }
                unset($lineItem);
            }
        }

        // 5. Aggregate Cart Totals and Division Breakdowns
        $grossSubtotal = 0.0;
        $totalDiscount = 0.0;
        $retailSubtotal = 0.0;
        $retailDiscount = 0.0;
        $coffeeSubtotal = 0.0;
        $coffeeDiscount = 0.0;

        foreach ($calculatedItems as $item) {
            $grossSubtotal += (float) $item['base_subtotal'];
            $totalDiscount += (float) $item['discount_amount'];

            if ($item['division'] === 'coffee') {
                $coffeeSubtotal += (float) $item['base_subtotal'];
                $coffeeDiscount += (float) $item['discount_amount'];
            } else {
                $retailSubtotal += (float) $item['base_subtotal'];
                $retailDiscount += (float) $item['discount_amount'];
            }
        }

        $grossSubtotal = round($grossSubtotal, 2);
        $totalDiscount = round($totalDiscount, 2);
        $finalTotal = max(0, round($grossSubtotal - $totalDiscount, 2));

        $retailFinal = max(0, round($retailSubtotal - $retailDiscount, 2));
        $coffeeFinal = max(0, round($coffeeSubtotal - $coffeeDiscount, 2));

        return [
            'items' => array_values($calculatedItems),
            'summary' => [
                'total_items' => count($calculatedItems),
                'subtotal' => $grossSubtotal,
                'discount_amount' => $totalDiscount,
                'tax_amount' => 0.00,
                'total_amount' => $finalTotal,
                'division_breakdown' => [
                    'retail' => [
                        'subtotal' => round($retailSubtotal, 2),
                        'discount_amount' => round($retailDiscount, 2),
                        'final_total' => $retailFinal,
                    ],
                    'coffee' => [
                        'subtotal' => round($coffeeSubtotal, 2),
                        'discount_amount' => round($coffeeDiscount, 2),
                        'final_total' => $coffeeFinal,
                    ],
                ],
            ],
            'applied_promotions' => $appliedPromotions,
        ];
    }
}
