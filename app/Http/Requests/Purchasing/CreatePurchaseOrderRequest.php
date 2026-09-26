<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'order_date' => 'nullable|date_format:Y-m-d',
            'expected_delivery_date' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.ordered_qty' => 'required|numeric|min:0.0001',
            'items.*.unit_id' => 'nullable|integer|exists:units,id',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ];
    }
}
