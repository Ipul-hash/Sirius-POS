<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with(['products'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data category berhasil diambil',
            'data' => $categories
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category berhasil ditambahkan',
            'data' => $category
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['products']);

        return response()->json([
            'success' => true,
            'message' => 'Detail category berhasil diambil',
            'data' => $category
        ], 200);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category berhasil diupdate',
            'data' => $category
        ], 200);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category berhasil dihapus',
            'data' => null
        ], 200);
    }
}