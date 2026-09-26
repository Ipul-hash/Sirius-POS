<?php

namespace App\Http\Requests\Pos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetProductsRequest extends FormRequest
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
            'branch_id' => 'nullable|integer|exists:branches,id',
            'search' => 'nullable|string|max:100',
            'division' => 'nullable|string|in:retail,coffee,all',
            'category_id' => 'nullable|integer|exists:categories,id',
            'type' => 'nullable|string|in:standard,composite,raw_material,all',
        ];
    }
}
