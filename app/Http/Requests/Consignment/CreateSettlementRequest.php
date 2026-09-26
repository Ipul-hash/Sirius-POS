<?php

namespace App\Http\Requests\Consignment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateSettlementRequest extends FormRequest
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
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'status' => 'nullable|string|in:draft,approved,paid',
            'notes' => 'nullable|string|max:500',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.initial_stock_qty' => 'nullable|integer|min:0',
            'items.*.received_qty' => 'nullable|integer|min:0',
            'items.*.sold_qty' => 'nullable|integer|min:0',
            'items.*.returned_damaged_qty' => 'nullable|integer|min:0',
            'items.*.closing_stock_qty' => 'nullable|integer|min:0',
        ];
    }
}
