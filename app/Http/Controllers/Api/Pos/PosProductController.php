<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\GetProductsRequest;
use App\Http\Resources\Pos\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class PosProductController extends Controller
{
    public function index(GetProductsRequest $request): JsonResponse
    {
        $query = Product::with([
            'category',
            'primaryUnit',
            'priceTiers',
            'boms.materialProduct.primaryUnit',
            'boms.unit',
        ])->where('is_active', true);

        $division = $request->input('division', 'all');
        if ($division && $division !== 'all') {
            $query->where('division', $division);
        }

        $type = $request->input('type');
        if ($type && $type !== 'all') {
            $query->where('type', $type);
        } elseif (! $type) {
            $query->whereIn('type', ['standard', 'composite']);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('barcode', $search)
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('division', 'asc')->orderBy('name', 'asc')->get();

        return response()->json([
            'success' => true,
            'count' => $products->count(),
            'data' => ProductResource::collection($products),
        ]);
    }
}
