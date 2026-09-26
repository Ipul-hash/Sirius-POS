<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptRequest extends FormRequest
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
            'purchase_order_id' => 'required|integer|exists:purchase_orders,id',
            'invoice_ref_number' => 'required|string|max:100',
            'location_id' => 'nullable|integer|exists:locations,id',
            'receipt_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.po_item_id' => 'nullable|integer|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.received_qty' => 'required|numeric|min:0.0001',
            'items.*.batch_no' => 'nullable|string|max:64',
            'items.*.expired_at' => 'nullable|date_format:Y-m-d',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ];
    }
}
