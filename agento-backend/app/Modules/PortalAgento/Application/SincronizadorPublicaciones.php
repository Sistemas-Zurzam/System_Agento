<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\PortalAgento\Domain\FlujoPublicacion;
use App\Modules\PortalAgento\Infrastructure\CacheAgento;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\PortalAgento\Models\PublicacionEvento;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Closure;
use DateTimeInterface;

/**
 * Mantiene la publicación alineada con el estado del proceso (observer del proceso + comando programado).
 * Nunca cambia el estado del proceso por su cuenta, salvo marcar/retirar la publicación externa cuando
 * una programada sale sola o cuando una persona retira desde Agento.
 */
class SincronizadorPublicaciones
{
    /** Mientras sea > 0 el observer no sincroniza (la acción en curso ya lo hace de forma explícita). */
    private static int $suspendido = 0;

    /** @var array<int, true> */
    private static array $enCurso = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public function suspendido(Closure $accion): mixed
    {
        self::$suspendido++;
        try {
            return $accion();
        } finally {
            self::$suspendido--;
        }
    }

    public function marcarExterna(ProcesoSeleccion $proceso): void
    {
        if ($proceso->publicacion_externa && $proceso->retirada_at === null) {
            return;
        }

        $this->suspendido(function () use ($proceso) {
            $proceso->forceFill([
                'publicacion_externa' => true,
                'publicada_externa_at' => $proceso->publicada_externa_at ?? now(),
                'retirada_at' => null,
            ])->save();
        });
    }

    /**
     * Retira solo la publicación externa. Si el proceso sigue publicado internamente no se marca como
     * retirado (eso cortaría también la publicación interna).
     */
    public function retirarExterna(ProcesoSeleccion $proceso): void
    {
        $this->suspendido(function () use ($proceso) {
            $proceso->forceFill([
                'publicacion_externa' => false,
                'retirada_at' => $proceso->publicacion_interna ? $proceso->retirada_at : now(),
            ])->save();
        });
    }

    /**
     * Alinea la publicación del proceso. Devuelve true si cambió el estado.
     */
    public function sincronizar(ProcesoSeleccion $proceso, ?DateTimeInterface $ahora = null): bool
    {
        if (self::$suspendido > 0 || isset(self::$enCurso[$proceso->id])) {
            return false;
        }
        self::$enCurso[$proceso->id] = true;

        try {
            return (bool) EmpresaContext::como((int) $proceso->empresa_id, fn () => $this->alinear($proceso, $ahora ?? now()));
        } finally {
            unset(self::$enCurso[$proceso->id]);
        }
    }

    /**
     * Recorre las publicaciones de UNA empresa (el comando llama aquí dentro de EmpresaContext::como).
     */
    public function sincronizarEmpresa(int $empresaId, ?DateTimeInterface $ahora = null): int
    {
        $cambios = 0;
        Publicacion::query()
            ->whereIn('estado_publicacion', [Publicacion::PROGRAMADA, Publicacion::PUBLICADA, Publicacion::PAUSADA])
            ->with('proceso')
            ->orderBy('id')
            ->each(function (Publicacion $publicacion) use ($ahora, &$cambios) {
                if ($publicacion->proceso && $this->sincronizar($publicacion->proceso, $ahora)) {
                    $cambios++;
                }
            });

        return $cambios;
    }

    private function alinear(ProcesoSeleccion $proceso, DateTimeInterface $ahora): bool
    {
        $publicacion = Publicacion::query()->where('proceso_id', $proceso->id)->first();
        if (! $publicacion) {
            return false;
        }

        // El texto buscable depende del proceso (cargo, empresa confidencial): se mantiene al día.
        $texto = app(PublicacionService::class)->textoBusqueda($proceso, $publicacion);
        if ($texto !== $publicacion->texto_busqueda) {
            $publicacion->texto_busqueda = $texto;
            $publicacion->save();
        }
        // El salario, la descripción, etc. se leen del proceso: cualquier cambio invalida la caché pública.
        CacheAgento::invalidar();

        $decision = FlujoPublicacion::estadoSegunProceso($publicacion, $proceso, $ahora);
        if ($decision === null) {
            return false;
        }

        $desde = $publicacion->estado_publicacion;
        if ($desde === Publicacion::PROGRAMADA && $decision['estado'] === Publicacion::PUBLICADA) {
            $this->marcarExterna($proceso);
            $publicacion->publicada_at = now();
        }
        if ($decision['estado'] === Publicacion::RETIRADA || $decision['estado'] === Publicacion::VENCIDA) {
            $publicacion->retirada_at = now();
            $publicacion->motivo_retiro = $decision['motivo'];
        }

        $publicacion->estado_publicacion = $decision['estado'];
        $publicacion->pausada_por = $decision['pausada_por'];
        $publicacion->version = $publicacion->version + 1;
        $publicacion->save();

        PublicacionEvento::create([
            'empresa_id' => $publicacion->empresa_id,
            'publicacion_id' => $publicacion->id,
            'evento' => 'sincronizada',
            'estado_desde' => $desde,
            'estado_hasta' => $decision['estado'],
            'usuario_id' => null,
            'detalle' => $decision['motivo'],
        ]);

        return true;
    }
}
