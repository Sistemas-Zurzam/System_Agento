<?php

namespace App\Modules\PortalAgento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Perfil público de la empresa en Agento (sector, tamaño, descripción, sitio web).
 */
class PerfilEmpresaRequest extends FormRequest
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
            'sector' => ['nullable', 'string', 'max:80'],
            'tamano' => ['nullable', 'string', 'max:40'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'sitio_web' => ['nullable', 'url:http,https', 'max:255'],
        ];
    }
}
