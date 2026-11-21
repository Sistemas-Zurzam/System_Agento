<?php

namespace App\Modules\PortalAgento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuardarPublicacionRequest extends FormRequest
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
            'titulo_publico' => ['nullable', 'string', 'max:150'],
            'resumen' => ['nullable', 'string', 'max:300'],
            'area_id' => ['nullable', 'integer', 'exists:agento_areas,id'],
            'nivel_id' => ['nullable', 'integer', 'exists:agento_niveles,id'],
            'jornada_id' => ['nullable', 'integer', 'exists:agento_jornadas,id'],
            'ubigeo_codigo' => ['nullable', 'string', 'size:6', 'exists:agento_ubigeo,codigo'],
            'sin_experiencia' => ['sometimes', 'boolean'],
            'inclusiva' => ['sometimes', 'boolean'],
            'vigente_hasta' => ['nullable', 'date', 'after:today'],
            'beneficios' => ['sometimes', 'array', 'max:20'],
            'beneficios.*' => ['integer', 'distinct', 'exists:agento_beneficios,id'],
            // Bloqueo optimista: si llega y no coincide con la versión actual, responde 409.
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
