<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $promotions = Promotion::with(['rules.conditionProduct', 'rules.rewardProduct'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data promotion berhasil diambil',
            'data' => $promotions
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:promotions,code|max:50',
            'type' => 'required|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'rules' => 'nullable|array',
            'rules.*.condition_product_id' => 'nullable|exists:products,id',
            'rules.*.condition_min_qty' => 'nullable|numeric|min:0',
            'rules.*.reward_product_id' => 'nullable|exists:products,id',
            'rules.*.reward_discount_type' => 'nullable|string|max:50',
            'rules.*.reward_discount_value' => 'nullable|numeric|min:0',
        ]);

        $rulesData = $validated['rules'] ?? [];
        unset($validated['rules']);

        $promotion = Promotion::create($validated);

        if (!empty($rulesData)) {
            $promotion->rules()->createMany($rulesData);
        }

        $promotion->load(['rules.conditionProduct', 'rules.rewardProduct']);

        return response()->json([
            'success' => true,
            'message' => 'Promotion berhasil ditambahkan',
            'data' => $promotion
        ], 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $promotion->load(['rules.conditionProduct', 'rules.rewardProduct']);

        return response()->json([
            'success' => true,
            'message' => 'Detail promotion berhasil diambil',
            'data' => $promotion
        ], 200);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:promotions,code,' . $promotion->id,
            'type' => 'sometimes|required|string|max:50',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'rules' => 'nullable|array',
            'rules.*.id' => 'nullable|exists:promotion_rules,id',
            'rules.*.condition_product_id' => 'nullable|exists:products,id',
            'rules.*.condition_min_qty' => 'nullable|numeric|min:0',
            'rules.*.reward_product_id' => 'nullable|exists:products,id',
            'rules.*.reward_discount_type' => 'nullable|string|max:50',
            'rules.*.reward_discount_value' => 'nullable|numeric|min:0',
        ]);

        if (isset($validated['rules'])) {
            $rulesData = $validated['rules'];
            unset($validated['rules']);
            
            $promotion->update($validated);

            $existingRuleIds = [];
            foreach ($rulesData as $ruleData) {
                if (isset($ruleData['id'])) {
                    $rule = $promotion->rules()->find($ruleData['id']);
                    if ($rule) {
                        $rule->update($ruleData);
                        $existingRuleIds[] = $rule->id;
                    }
                } else {
                    $newRule = $promotion->rules()->create($ruleData);
                    $existingRuleIds[] = $newRule->id;
                }
            }

            // Hapus rule yang tidak disertakan saat update (opsional, tergantung kebutuhan)
            $promotion->rules()->whereNotIn('id', $existingRuleIds)->delete();
        } else {
            $promotion->update($validated);
        }

        $promotion->load(['rules.conditionProduct', 'rules.rewardProduct']);

        return response()->json([
            'success' => true,
            'message' => 'Promotion berhasil diupdate',
            'data' => $promotion
        ], 200);
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->rules()->delete();
        $promotion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Promotion berhasil dihapus',
            'data' => null
        ], 200);
    }
}