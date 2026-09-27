<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecipeBom;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecipeBomController extends Controller
{
    public function index(): JsonResponse
    {
        $recipes = RecipeBom::with(['parentProduct', 'materialProduct', 'unit'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data recipe bom berhasil diambil',
            'data' => $recipes
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_product_id' => 'required|exists:products,id',
            'material_product_id' => 'required|exists:products,id',
            'unit_id' => 'required|exists:units,id',
            'quantity' => 'required|numeric|min:0',
            'yield_loss_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $recipe = RecipeBom::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Recipe bom berhasil ditambahkan',
            'data' => $recipe
        ], 201);
    }

    public function show(RecipeBom $recipeBom): JsonResponse
    {
        $recipeBom->load(['parentProduct', 'materialProduct', 'unit']);

        return response()->json([
            'success' => true,
            'message' => 'Detail recipe bom berhasil diambil',
            'data' => $recipeBom
        ], 200);
    }

    public function update(Request $request, RecipeBom $recipeBom): JsonResponse
    {
        $validated = $request->validate([
            'parent_product_id' => 'sometimes|required|exists:products,id',
            'material_product_id' => 'sometimes|required|exists:products,id',
            'unit_id' => 'sometimes|required|exists:units,id',
            'quantity' => 'sometimes|required|numeric|min:0',
            'yield_loss_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $recipeBom->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Recipe bom berhasil diupdate',
            'data' => $recipeBom
        ], 200);
    }

    public function destroy(RecipeBom $recipeBom): JsonResponse
    {
        $recipeBom->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recipe bom berhasil dihapus',
            'data' => null
        ], 200);
    }
}   