<?php

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Models\Mensaje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Mensaje
 */
class MensajeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direccion' => $this->direccion,
            'tipo' => $this->tipo,
            'texto' => $this->texto_mensaje,
            'estado' => $this->estado,
            'error' => $this->error,
            'autor' => $this->whenLoaded('autor', fn () => $this->autor?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
