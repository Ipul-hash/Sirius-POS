<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::with(['locations', 'orders', 'cashShifts'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data branch berhasil diambil',
            'data' => $branches
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:branches,code|max:50',
            'address' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $branch = Branch::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Branch berhasil ditambahkan',
            'data' => $branch
        ], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        $branch->load(['locations', 'orders', 'cashShifts']);

        return response()->json([
            'success' => true,
            'message' => 'Detail branch berhasil diambil',
            'data' => $branch
        ], 200);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:branches,code,' . $branch->id,
            'address' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $branch->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Branch berhasil diupdate',
            'data' => $branch
        ], 200);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $branch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch berhasil dihapus',
            'data' => null
        ], 200);
    }
}