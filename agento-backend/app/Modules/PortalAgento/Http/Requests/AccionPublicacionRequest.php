<?php

namespace App\Modules\PortalAgento\Http\Requests;

use App\Modules\PortalAgento\Domain\FlujoPublicacion;
use Illuminate\Foundation\Http\FormRequest;

class AccionPublicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->route('accion'), FlujoPublicacion::ACCIONES, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:255'],
            'programada_para' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date'],
        ];
    }
}
