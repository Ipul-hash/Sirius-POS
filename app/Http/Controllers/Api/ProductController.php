<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::with(['primaryUnit', 'category', 'supplier', 'uomConversions', 'boms', 'batches', 'priceTiers'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data product berhasil diambil',
            'data' => $products
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku|max:100',
            'type' => 'required|string|max:50',
            'division' => 'required|string|max:50',
            'primary_unit_id' => 'required|exists:units,id',
            'category_id' => 'required|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'min_stock_alert' => 'nullable|integer|min:0',
            'track_expiry' => 'nullable|boolean',
            'is_consignment' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product berhasil ditambahkan',
            'data' => $product
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['primaryUnit', 'category', 'supplier', 'uomConversions', 'boms', 'batches', 'priceTiers']);

        return response()->json([
            'success' => true,
            'message' => 'Detail product berhasil diambil',
            'data' => $product
        ], 200);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|required|string|max:100|unique:products,sku,' . $product->id,
            'type' => 'sometimes|required|string|max:50',
            'division' => 'sometimes|required|string|max:50',
            'primary_unit_id' => 'sometimes|required|exists:units,id',
            'category_id' => 'sometimes|required|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'selling_price' => 'sometimes|required|numeric|min:0',
            'min_stock_alert' => 'nullable|integer|min:0',
            'track_expiry' => 'nullable|boolean',
            'is_consignment' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product berhasil diupdate',
            'data' => $product
        ], 200);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product berhasil dihapus',
            'data' => null
        ], 200);
    }
}