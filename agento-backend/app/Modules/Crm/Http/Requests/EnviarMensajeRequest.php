<?php

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnviarMensajeRequest extends FormRequest
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
        // WhatsApp admite hasta 4096 caracteres en un mensaje de texto.
        return ['texto_mensaje' => ['required', 'string', 'max:4096']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['texto_mensaje' => 'mensaje'];
    }
}
