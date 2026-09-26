<?php

namespace App\Http\Requests\Kds;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetKdsTicketsRequest extends FormRequest
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
            'status' => 'nullable|string',
            'date' => 'nullable|date_format:Y-m-d',
        ];
    }
}
