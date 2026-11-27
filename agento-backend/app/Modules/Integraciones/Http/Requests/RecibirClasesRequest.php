<?php

namespace App\Modules\Integraciones\Http\Requests;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Integraciones\Models\ClaseExterna;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato del envío de clases dictadas (ver docs/integraciones/firme-studio.md). La autenticación se hace
 * antes, en el controlador, con el token; aquí solo se valida el formato del cuerpo.
 */
class RecibirClasesRequest extends FormRequest
{
    /**
     * Cada empresa define su forma de pagar: solo recibe clases si activó «Pago por clases» en su ficha. La
     * empresa sale de la credencial (AutenticarIntegracion), nunca del cuerpo.
     */
    public function authorize(): Response
    {
        $credencial = $this->attributes->get('credencial_integracion');
        $habilitada = $credencial && (bool) Empresa::whereKey($credencial->empresa_id)->value('pago_por_clases_habilitado');

        return $habilitada
            ? Response::allow()
            : Response::deny('La empresa no tiene habilitado el pago por clases en Agento.');
    }

    public function rules(): array
    {
        return [
            'ruc' => ['nullable', 'string', 'size:11'],
            'referencia' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'periodo_inicio' => ['required', 'date_format:Y-m-d'],
            'periodo_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_inicio'],
            'enviado_por' => ['nullable', 'string', 'max:150'],
            'clases' => ['required', 'array', 'min:1', 'max:'.(int) config('integraciones.max_clases_por_envio')],
            'clases.*.clase_id' => ['required', 'string', 'max:60', 'distinct'],
            'clases.*.fecha' => ['required', 'date_format:Y-m-d'],
            'clases.*.hora_inicio' => ['required', 'date_format:H:i'],
            'clases.*.hora_fin' => ['required', 'date_format:H:i'],
            'clases.*.tipo_clase' => ['nullable', 'string', 'max:60'],
            'clases.*.sede' => ['nullable', 'string', 'max:100'],
            'clases.*.estado' => ['required', Rule::in(ClaseExterna::ESTADOS)],
            'clases.*.motivo_suplencia' => ['nullable', 'string', 'max:120'],
            'clases.*.instructora_titular' => ['required', 'array'],
            'clases.*.instructora_titular.dni' => ['required', 'string', 'max:20'],
            'clases.*.instructora_titular.nombre' => ['required', 'string', 'max:150'],
            'clases.*.instructora_que_dicto' => ['nullable', 'array'],
            'clases.*.instructora_que_dicto.dni' => ['required_with:clases.*.instructora_que_dicto', 'string', 'max:20'],
            'clases.*.instructora_que_dicto.nombre' => ['required_with:clases.*.instructora_que_dicto', 'string', 'max:150'],
        ];
    }
}
