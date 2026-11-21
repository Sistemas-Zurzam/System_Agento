<?php

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Models\CanalWhatsapp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un canal de WhatsApp. Al editar, el token de acceso y el App secret vacíos
 * conservan los guardados (nunca se devuelven al frontend).
 */
class GuardarCanalWhatsappRequest extends FormRequest
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
        $canal = $this->route('canal');
        $creando = ! $canal instanceof CanalWhatsapp;
        $secreto = $creando ? ['required', 'string', 'max:2000'] : ['nullable', 'string', 'max:2000'];

        return [
            'nombre' => ['required', 'string', 'max:120'],
            // Un número solo puede estar en un canal: Meta envía sus avisos a una sola URL.
            'phone_number_id' => [
                'required', 'string', 'regex:/^\d{5,40}$/',
                Rule::unique('crm_canales_whatsapp', 'phone_number_id')->ignore($creando ? null : $canal->id),
            ],
            'waba_id' => ['nullable', 'string', 'regex:/^\d{5,40}$/'],
            'numero_visible' => ['nullable', 'string', 'max:30'],
            'access_token' => $secreto,
            'app_secret' => $secreto,
            'verify_token' => ['required', 'string', 'min:8', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_number_id.regex' => 'El Phone number ID son solo números (lo ves en Meta → WhatsApp → Configuración de la API).',
            'phone_number_id.unique' => 'Ese número de WhatsApp ya está registrado en otro canal.',
            'waba_id.regex' => 'El ID de la cuenta de WhatsApp Business son solo números.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone_number_id' => 'Phone number ID',
            'access_token' => 'token de acceso permanente',
            'app_secret' => 'App secret',
            'verify_token' => 'token de verificación',
        ];
    }
}
