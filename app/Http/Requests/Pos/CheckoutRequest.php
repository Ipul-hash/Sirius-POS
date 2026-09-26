<?php

namespace App\Http\Requests\Pos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
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
            'branch_id' => 'required|integer|exists:branches,id',
            'customer_phone' => 'nullable|string|max:20',
            'customer_name' => 'nullable|string|max:100',
            'payment_method' => 'required|string|in:cash,qris',
            'amount_paid' => 'required|numeric|min:0',
            'queue_number' => 'nullable|string|max:20',
            'reference_no' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string|max:255',
        ];
    }
}
