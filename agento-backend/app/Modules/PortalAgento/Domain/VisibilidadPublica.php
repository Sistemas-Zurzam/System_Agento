<?php

namespace App\Modules\PortalAgento\Domain;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Database\Eloquent\Builder;

/**
 * ÚNICO lugar donde se decide qué publicaciones ve el público de Agento.
 * Todos los endpoints públicos (/api/v1) parten de esta consulta.
 *
 * Visible = publicada o pausada (la pausada se muestra con Postular deshabilitado), dentro de su vigencia,
 * con el proceso abierto o en pausa, publicado hacia afuera y no retirado, y de una empresa activa con el
 * servicio de reclutamiento. Las ofertas de empresas confidenciales SÍ son visibles, pero nunca con su empresa.
 */
final class VisibilidadPublica
{
    /**
     * @param  Builder<Publicacion>  $consulta
     * @return Builder<Publicacion>
     */
    public static function aplicar(Builder $consulta): Builder
    {
        $ahora = now();

        return $consulta
            ->whereIn('agento_publicaciones.estado_publicacion', [Publicacion::PUBLICADA, Publicacion::PAUSADA])
            ->where(fn (Builder $q) => $q->whereNull('agento_publicaciones.vigente_hasta')->orWhere('agento_publicaciones.vigente_hasta', '>', $ahora))
            ->whereHas('proceso', fn (Builder $proceso) => $proceso
                ->whereIn('estado', [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA])
                ->whereNull('retirada_at')
                ->where('publicacion_externa', true)
                ->where(fn (Builder $q) => $q->whereNull('fecha_cierre_postulacion')->orWhere('fecha_cierre_postulacion', '>=', $ahora->toDateString())))
            ->whereHas('empresa', fn (Builder $empresa) => $empresa->where('servicio_reclutamiento', true)->where('activa', true));
    }
}
