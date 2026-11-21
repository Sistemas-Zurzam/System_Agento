<?php

namespace App\Modules\PortalAgento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestacarPublicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destacar' => ['required', 'boolean'],
            'hasta' => ['nullable', 'date', 'after:today'],
        ];
    }
}
