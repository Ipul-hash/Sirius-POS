<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExpiryAlertsRequest extends FormRequest
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
            'days_threshold' => 'nullable|integer|min:1|max:365',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'location_id' => 'nullable|integer|exists:locations,id',
        ];
    }
}
