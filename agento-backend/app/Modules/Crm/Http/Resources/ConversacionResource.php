<?php

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Models\Conversacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversacion
 */
class ConversacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'canal' => $this->whenLoaded('canal', fn () => [
                'id' => $this->canal->id,
                'nombre' => $this->canal->nombre,
                'numero_visible' => $this->canal->numero_visible,
            ]),
            'telefono' => '+'.$this->wa_id,
            'nombre_contacto' => $this->nombre_contacto,
            'ultimo_texto_mensaje' => $this->ultimo_texto_mensaje,
            'ultimo_mensaje_at' => $this->ultimo_mensaje_at?->toIso8601String(),
            'ventana_abierta' => $this->ventanaAbierta(),
            'ventana_cierra_at' => $this->ultimo_entrante_at?->copy()->addHours(Conversacion::VENTANA_HORAS)->toIso8601String(),
            'no_leidos' => $this->no_leidos,
        ];
    }
}
