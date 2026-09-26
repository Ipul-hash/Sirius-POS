<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordWasteRequest extends FormRequest
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
            'location_id' => 'required|integer|exists:locations,id',
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|numeric|min:0.0001',
            'reason' => 'required|string|in:expired,damaged,barista_spill,dial_in_beans,pest_damage,other',
            'notes' => 'nullable|string|max:500',
            'batch_id' => 'nullable|integer|exists:inventory_batches,id',
        ];
    }
}
