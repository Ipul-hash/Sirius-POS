<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductPriceTier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductPriceTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ProductPriceTier::with(['product'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data product price tier berhasil diambil',
            'data' => $tiers
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'min_qty' => 'required|numeric|min:0',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $tier = ProductPriceTier::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product price tier berhasil ditambahkan',
            'data' => $tier
        ], 201);
    }

    public function show(ProductPriceTier $productPriceTier): JsonResponse
    {
        $productPriceTier->load(['product']);

        return response()->json([
            'success' => true,
            'message' => 'Detail product price tier berhasil diambil',
            'data' => $productPriceTier
        ], 200);
    }

    public function update(Request $request, ProductPriceTier $productPriceTier): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'min_qty' => 'sometimes|required|numeric|min:0',
            'unit_price' => 'sometimes|required|numeric|min:0',
        ]);

        $productPriceTier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product price tier berhasil diupdate',
            'data' => $productPriceTier
        ], 200);
    }

    public function destroy(ProductPriceTier $productPriceTier): JsonResponse
    {
        $productPriceTier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product price tier berhasil dihapus',
            'data' => null
        ], 200);
    }
}