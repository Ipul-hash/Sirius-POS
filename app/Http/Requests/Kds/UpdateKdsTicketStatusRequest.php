<?php

namespace App\Http\Requests\Kds;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKdsTicketStatusRequest extends FormRequest
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
            'status' => 'required|string|in:start,preparing,ready,collected,queued',
            'barista_id' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status tiket wajib diisi.',
            'status.in' => 'Status tiket tidak valid. Pilihan: start, preparing, ready, collected, queued.',
            'barista_id.exists' => 'Barista yang dipilih tidak terdaftar di sistem.',
        ];
    }
}
