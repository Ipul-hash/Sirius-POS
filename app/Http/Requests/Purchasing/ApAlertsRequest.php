<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApAlertsRequest extends FormRequest
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
            'days_threshold' => 'nullable|integer|min:0|max:180',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ];
    }
}
