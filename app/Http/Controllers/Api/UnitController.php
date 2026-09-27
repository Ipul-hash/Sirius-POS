<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\ProductUomConversion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        $units = Unit::with(['products', 'uomConversions.product', 'uomConversions.fromUnit', 'uomConversions.toUnit'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data unit dan konversi berhasil diambil',
            'data' => $units
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:units,code|max:50',
        ]);

        $unit = Unit::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil ditambahkan',
            'data' => $unit
        ], 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        $unit->load(['products', 'uomConversions.product', 'uomConversions.fromUnit', 'uomConversions.toUnit']);

        return response()->json([
            'success' => true,
            'message' => 'Detail unit berhasil diambil',
            'data' => $unit
        ], 200);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:units,code,' . $unit->id,
        ]);

        $unit->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil diupdate',
            'data' => $unit
        ], 200);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $unit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil dihapus',
            'data' => null
        ], 200);
    }

    public function storeConversion(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_unit_id' => 'required|exists:units,id',
            'to_unit_id' => 'required|exists:units,id',
            'multiplier' => 'required|numeric|min:0',
            'is_purchase_unit' => 'nullable|boolean',
        ]);

        $conversion = $unit->uomConversions()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product uom conversion berhasil ditambahkan ke unit ini',
            'data' => $conversion
        ], 201);
    }

    public function updateConversion(Request $request, Unit $unit, ProductUomConversion $conversion): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'sometimes|required|exists:products,id',
            'from_unit_id' => 'sometimes|required|exists:units,id',
            'to_unit_id' => 'sometimes|required|exists:units,id',
            'multiplier' => 'sometimes|required|numeric|min:0',
            'is_purchase_unit' => 'nullable|boolean',
        ]);

        $conversion->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product uom conversion berhasil diupdate',
            'data' => $conversion
        ], 200);
    }

    public function destroyConversion(Unit $unit, ProductUomConversion $conversion): JsonResponse
    {
        $conversion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product uom conversion berhasil dihapus',
            'data' => null
        ], 200);
    }
}