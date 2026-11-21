<?php

namespace App\Modules\PortalAgento\Http\Requests;

use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulario único «Publicar un puesto». empresa_id NO es un campo aceptado: sale del usuario autenticado.
 * `area_interna_id` es el área de la empresa (se valida contra las áreas del usuario en SolicitudService);
 * `area_id` es el área del catálogo público de Agento.
 */
class PublicacionDirectaRequest extends FormRequest
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
            'cargo' => ['required', 'string', 'min:3', 'max:150'],
            'area_interna_id' => ['required', 'integer'],
            'plazas' => ['required', 'integer', 'min:1', 'max:50'],
            'salario_min' => ['required', 'numeric', 'min:0'],
            'salario_max' => ['required', 'numeric', 'gte:salario_min'],
            'moneda' => ['nullable', Rule::in(['PEN', 'USD'])],
            'tipo_contrato' => ['required', Rule::in(SolicitudPersonal::TIPOS_CONTRATO)],
            'modalidad' => ['required', Rule::in(SolicitudPersonal::MODALIDADES)],
            'fecha_requerida' => ['nullable', 'date', 'after_or_equal:today'],
            'descripcion' => ['required', 'string', 'min:30', 'max:2000'],
            'actividades' => ['nullable', 'string', 'max:4000'],
            'requisitos' => ['nullable', 'string', 'max:4000'],
            'habilidades' => ['required', 'array', 'min:1', 'max:20'],
            'habilidades.*.nombre' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'habilidades.*.nivel' => ['required', 'integer', 'min:1', 'max:5'],

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
            'publicar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $datos = parent::validated();
        // La solicitud exige una fecha requerida: sin ella se usa el cierre previsto de la oferta.
        $datos['fecha_requerida'] ??= now()->addDays((int) config('portal_agento.vigencia_dias', 30))->toDateString();
        $datos['publicar'] = $this->boolean('publicar');

        return $datos;
    }
}
