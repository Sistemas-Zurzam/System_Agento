<?php

namespace App\Modules\PortalAgento\Http\Resources;

use App\Modules\PortalAgento\Models\Publicacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Publicación para el panel interno (datos propios de la publicación, sin datos del candidato).
 *
 * @mixin Publicacion
 */
class PublicacionAgentoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = rtrim((string) config('portal_agento.url_portal'), '/');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'estado_publicacion' => $this->estado_publicacion,
            'pausada_por' => $this->pausada_por,
            'titulo_publico' => $this->titulo_publico,
            'resumen' => $this->resumen,
            'area_id' => $this->area_id,
            'nivel_id' => $this->nivel_id,
            'jornada_id' => $this->jornada_id,
            'ubigeo_codigo' => $this->ubigeo_codigo,
            'ubicacion_texto' => $this->ubicacion_texto,
            'sin_experiencia' => $this->sin_experiencia,
            'inclusiva' => $this->inclusiva,
            'destacada' => $this->destacada,
            'destacada_hasta' => $this->destacada_hasta?->toIso8601String(),
            'programada_para' => $this->programada_para?->toIso8601String(),
            'publicada_at' => $this->publicada_at?->toIso8601String(),
            'vigente_hasta' => $this->vigente_hasta?->toDateString(),
            'retirada_at' => $this->retirada_at?->toIso8601String(),
            'motivo_retiro' => $this->motivo_retiro,
            'version' => $this->version,
            'beneficios' => $this->relationLoaded('beneficios') ? $this->beneficios->pluck('id')->values()->all() : [],
            'url_publica' => $base !== '' ? "{$base}/empleos/{$this->slug}" : null,
        ];
    }
}
