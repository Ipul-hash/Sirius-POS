<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        $units = Unit::with(['products'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data unit berhasil diambil',
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
        $unit->load(['products']);

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
}