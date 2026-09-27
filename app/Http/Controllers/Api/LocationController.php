<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        $locations = Location::with(['branch', 'batches'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data location berhasil diambil',
            'data' => $locations
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:locations,code|max:50',
            'division' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $location = Location::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Location berhasil ditambahkan',
            'data' => $location
        ], 201);
    }

    public function show(Location $location): JsonResponse
    {
        $location->load(['branch', 'batches']);

        return response()->json([
            'success' => true,
            'message' => 'Detail location berhasil diambil',
            'data' => $location
        ], 200);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'sometimes|required|exists:branches,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:locations,code,' . $location->id,
            'division' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ]);

        $location->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Location berhasil diupdate',
            'data' => $location
        ], 200);
    }

    public function destroy(Location $location): JsonResponse
    {
        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location berhasil dihapus',
            'data' => null
        ], 200);
    }
}