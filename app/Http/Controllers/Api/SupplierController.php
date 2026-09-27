<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        $suppliers = Supplier::with(['products', 'purchaseOrders', 'apInvoices', 'consignmentSettlements'])->get();

        return response()->json([
            'success' => true,
            'message' => 'List data supplier berhasil diambil',
            'data' => $suppliers
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:suppliers,code|max:50',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'is_consignment_vendor' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'revenue_share_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days' => 'nullable|integer|min:0',
        ]);

        $supplier = Supplier::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil ditambahkan',
            'data' => $supplier
        ], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load(['products', 'purchaseOrders', 'apInvoices', 'consignmentSettlements']);

        return response()->json([
            'success' => true,
            'message' => 'Detail supplier berhasil diambil',
            'data' => $supplier
        ], 200);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:suppliers,code,' . $supplier->id,
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'is_consignment_vendor' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'revenue_share_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_terms_days' => 'nullable|integer|min:0',
        ]);

        $supplier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil diupdate',
            'data' => $supplier
        ], 200);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supplier berhasil dihapus',
            'data' => null
        ], 200);
    }
}