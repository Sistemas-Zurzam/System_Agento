<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\PortalAgento\Domain\FlujoPublicacion;
use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\PortalAgento\Models\PublicacionEvento;
use App\Modules\PortalAgento\Models\Ubigeo;
use App\Modules\PortalAgento\Support\Texto;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use App\Modules\Reclutamiento\Application\ProcesoService;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Casos de uso internos de la publicación en Agento (panel de Reclutamiento). La empresa sale SIEMPRE
 * del usuario autenticado (AlcanceSeleccion), nunca del request.
 */
class PublicacionService
{
    public function __construct(
        private readonly ProcesoService $procesos,
        private readonly ChecklistPublicacion $checklist,
        private readonly PerfilPublicoEmpresa $perfiles,
        private readonly SincronizadorPublicaciones $sincronizador,
    ) {}

    public function obtener(ProcesoSeleccion $proceso): ?Publicacion
    {
        return Publicacion::query()->where('proceso_id', $proceso->id)
            ->with(['area', 'nivel', 'jornada', 'ubigeo', 'beneficios'])->first();
    }

    /**
     * @return array{publicacion: ?Publicacion, checklist: array<string, mixed>, acciones: list<string>}
     */
    public function ver(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso): array
    {
        $this->procesos->autorizarLectura($alcance, $proceso);
        $publicacion = $this->obtener($proceso);

        return [
            'publicacion' => $publicacion,
            'checklist' => $this->checklist->evaluar($proceso, $publicacion),
            'acciones' => $publicacion ? FlujoPublicacion::accionesDisponibles($publicacion->estado_publicacion) : ['guardar'],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function guardar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $datos): Publicacion
    {
        $this->procesos->autorizarLectura($alcance, $proceso);
        $this->validarProceso($proceso);

        return DB::transaction(function () use ($alcance, $proceso, $datos) {
            $publicacion = Publicacion::query()->where('proceso_id', $proceso->id)->lockForUpdate()->first();

            if ($publicacion && isset($datos['version']) && (int) $datos['version'] !== $publicacion->version) {
                throw new ConflictHttpException('La publicación fue modificada por otra persona. Recarga la pantalla para ver los cambios.');
            }

            $nueva = $publicacion === null;
            $publicacion ??= new Publicacion([
                'empresa_id' => $proceso->empresa_id,
                'proceso_id' => $proceso->id,
                'slug' => $this->slugPara($proceso),
                'estado_publicacion' => Publicacion::BORRADOR,
                'creada_por' => $alcance->usuario->id,
            ]);

            $ubigeo = isset($datos['ubigeo_codigo']) ? Ubigeo::query()->where('codigo', $datos['ubigeo_codigo'])->first() : null;

            $publicacion->fill([
                'titulo_publico' => $datos['titulo_publico'] ?? $publicacion->titulo_publico,
                'resumen' => $datos['resumen'] ?? $publicacion->resumen,
                'area_id' => $datos['area_id'] ?? $publicacion->area_id,
                'nivel_id' => $datos['nivel_id'] ?? $publicacion->nivel_id,
                'jornada_id' => $datos['jornada_id'] ?? $publicacion->jornada_id,
                'ubigeo_codigo' => $ubigeo?->codigo ?? $publicacion->ubigeo_codigo,
                'ubicacion_texto' => $ubigeo?->etiqueta ?? $publicacion->ubicacion_texto,
                'sin_experiencia' => $datos['sin_experiencia'] ?? $publicacion->sin_experiencia ?? false,
                'inclusiva' => $datos['inclusiva'] ?? $publicacion->inclusiva ?? false,
                'vigente_hasta' => array_key_exists('vigente_hasta', $datos) ? $this->finDeDia($datos['vigente_hasta']) : $publicacion->vigente_hasta,
                'actualizada_por' => $alcance->usuario->id,
            ]);
            if (! $nueva) {
                $publicacion->version = $publicacion->version + 1;
            }
            $publicacion->texto_busqueda = $this->textoBusqueda($proceso, $publicacion);
            $publicacion->save();

            if (array_key_exists('beneficios', $datos)) {
                $publicacion->beneficios()->sync($datos['beneficios'] ?? []);
            }

            // Perfil público de la empresa (slug estable): se asegura al preparar la primera publicación.
            $this->perfiles->asegurar($proceso->empresa()->first());

            $this->evento($publicacion, $nueva ? 'creada' : 'guardada', null, $publicacion->estado_publicacion, $alcance->usuario->id);

            return $publicacion;
        })->load(['area', 'nivel', 'jornada', 'ubigeo', 'beneficios']);
    }

    /**
     * @param  'publicar'|'programar'|'pausar'|'reanudar'|'retirar'|'renovar'  $accion
     * @param  array<string, mixed>  $datos  motivo (retirar), programada_para (programar), vigente_hasta (renovar)
     */
    public function ejecutar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, string $accion, array $datos = []): Publicacion
    {
        $this->procesos->autorizarLectura($alcance, $proceso);

        // El observer del proceso se suspende: esta acción ya deja la publicación y el proceso alineados.
        $publicacion = $this->sincronizador->suspendido(fn () => DB::transaction(function () use ($alcance, $proceso, $accion, $datos) {
            $publicacion = Publicacion::query()->where('proceso_id', $proceso->id)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['accion' => 'Guarda primero los datos de la publicación.']);
            $proceso = ProcesoSeleccion::query()->whereKey($proceso->id)->lockForUpdate()->firstOrFail();

            $desde = $publicacion->estado_publicacion;
            $destino = FlujoPublicacion::aplicar($desde, $accion);

            if (in_array($accion, ['publicar', 'programar', 'renovar'], true)) {
                $this->exigirChecklist($proceso, $publicacion, $datos);
            }

            match ($accion) {
                'publicar' => $this->publicar($alcance, $proceso, $publicacion),
                'programar' => $this->programar($proceso, $publicacion, $datos),
                'pausar' => $publicacion->pausada_por = 'agento',
                'reanudar' => $this->reanudar($proceso, $publicacion),
                'retirar' => $this->retirar($proceso, $publicacion, $datos),
                'renovar' => $this->renovar($alcance, $proceso, $publicacion, $datos),
            };

            $publicacion->estado_publicacion = $destino;
            $publicacion->version = $publicacion->version + 1;
            $publicacion->actualizada_por = $alcance->usuario->id;
            $publicacion->texto_busqueda = $this->textoBusqueda($proceso, $publicacion);
            $publicacion->save();

            $this->evento($publicacion, $accion, $desde, $destino, $alcance->usuario->id, $datos['motivo'] ?? null);

            return $publicacion;
        }));

        return $publicacion->load(['area', 'nivel', 'jornada', 'ubigeo', 'beneficios']);
    }

    public function destacar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, bool $destacar, ?string $hasta): Publicacion
    {
        $this->procesos->autorizarLectura($alcance, $proceso);

        $publicacion = Publicacion::query()->where('proceso_id', $proceso->id)->first()
            ?? throw ValidationException::withMessages(['accion' => 'Guarda primero los datos de la publicación.']);

        if ($destacar && ! in_array($publicacion->estado_publicacion, [Publicacion::PUBLICADA, Publicacion::PAUSADA], true)) {
            throw ValidationException::withMessages(['accion' => 'Solo se destaca una publicación que está publicada.']);
        }

        $publicacion->update([
            'destacada' => $destacar,
            'destacada_hasta' => $destacar ? $this->finDeDia($hasta) : null,
            'version' => $publicacion->version + 1,
            'actualizada_por' => $alcance->usuario->id,
        ]);
        $this->evento($publicacion, $destacar ? 'destacada' : 'destacado_quitado', null, $publicacion->estado_publicacion, $alcance->usuario->id);

        return $publicacion->load(['area', 'nivel', 'jornada', 'ubigeo', 'beneficios']);
    }

    /**
     * @return Collection<int, PublicacionEvento>
     */
    public function historial(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso): Collection
    {
        $this->procesos->autorizarLectura($alcance, $proceso);
        $publicacion = $this->obtener($proceso);

        return $publicacion ? $publicacion->eventos()->with('usuario:id,name')->limit(100)->get() : collect();
    }

    /**
     * Vista previa: la publicación tal como la vería el público (aunque aún no esté publicada).
     */
    public function paraVistaPrevia(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso): Publicacion
    {
        $this->procesos->autorizarLectura($alcance, $proceso);

        return $this->obtener($proceso)
            ?? throw ValidationException::withMessages(['publicacion' => 'Guarda primero los datos de la publicación para ver la vista previa.']);
    }

    // ── Pasos de cada acción ────────────────────────────────────────────────────────────────

    private function publicar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, Publicacion $publicacion): void
    {
        $this->prepararProceso($alcance, $proceso);
        $publicacion->publicada_at = now();
        $publicacion->programada_para = null;
        $publicacion->pausada_por = null;
        $publicacion->retirada_at = null;
        $publicacion->motivo_retiro = null;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function programar(ProcesoSeleccion $proceso, Publicacion $publicacion, array $datos): void
    {
        $cuando = isset($datos['programada_para']) ? Carbon::parse($datos['programada_para']) : null;
        if (! $cuando || $cuando->isPast()) {
            throw ValidationException::withMessages(['programada_para' => 'Indica una fecha y hora futura para programar.']);
        }
        if ($proceso->estado !== ProcesoSeleccion::ABIERTO) {
            throw ValidationException::withMessages(['accion' => 'Para programar, el proceso debe estar abierto. Publícalo desde «Publicar» si aún está en borrador.']);
        }
        $publicacion->programada_para = $cuando;
    }

    private function reanudar(ProcesoSeleccion $proceso, Publicacion $publicacion): void
    {
        if ($proceso->estado === ProcesoSeleccion::EN_PAUSA) {
            throw ValidationException::withMessages(['accion' => 'El proceso está en pausa: reanúdalo primero en el proceso.']);
        }
        $publicacion->pausada_por = null;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function retirar(ProcesoSeleccion $proceso, Publicacion $publicacion, array $datos): void
    {
        if (blank($datos['motivo'] ?? null)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo para retirar la publicación.']);
        }
        $publicacion->retirada_at = now();
        $publicacion->motivo_retiro = Str::limit((string) $datos['motivo'], 255, '');
        $publicacion->pausada_por = null;
        $this->sincronizador->retirarExterna($proceso);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function renovar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, Publicacion $publicacion, array $datos): void
    {
        $hasta = $this->finDeDia($datos['vigente_hasta'] ?? now()->addDays((int) config('portal_agento.vigencia_dias', 30))->toDateString());
        if ($hasta === null || $hasta->isPast()) {
            throw ValidationException::withMessages(['vigente_hasta' => 'La nueva vigencia debe ser una fecha futura.']);
        }

        $this->prepararProceso($alcance, $proceso);
        $publicacion->vigente_hasta = $hasta;
        $publicacion->publicada_at = now();
        $publicacion->pausada_por = null;
        $publicacion->retirada_at = null;
        $publicacion->motivo_retiro = null;
    }

    /**
     * El proceso queda abierto y publicado hacia afuera. Un borrador se abre con ProcesoService::publicar
     * (valida el perfil y deja su bitácora); si ya está abierto o en pausa solo se marca la publicación externa.
     */
    private function prepararProceso(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso): void
    {
        if ($proceso->estado === ProcesoSeleccion::BORRADOR) {
            $this->procesos->publicar($alcance, $proceso, false, true);

            return;
        }

        $this->validarProceso($proceso);
        $this->sincronizador->marcarExterna($proceso);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function exigirChecklist(ProcesoSeleccion $proceso, Publicacion $publicacion, array $datos): void
    {
        if (isset($datos['vigente_hasta'])) {
            $publicacion->vigente_hasta = $this->finDeDia($datos['vigente_hasta']);
        }

        $resultado = $this->checklist->evaluar($proceso, $publicacion);
        if (! $resultado['listo']) {
            $pendientes = collect($resultado['items'])->filter(fn (array $i) => $i['obligatorio'] && ! $i['ok'])->pluck('etiqueta')->all();
            throw ValidationException::withMessages(['checklist' => 'Falta completar: '.implode('; ', $pendientes).'.']);
        }
    }

    private function validarProceso(ProcesoSeleccion $proceso): void
    {
        if (! in_array($proceso->estado, [ProcesoSeleccion::BORRADOR, ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA], true)) {
            throw ValidationException::withMessages(['proceso' => 'Solo se publican procesos en borrador, abiertos o en pausa.']);
        }
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────────────

    private function slugPara(ProcesoSeleccion $proceso): string
    {
        // El código del proceso es único global: el slug no puede colisionar.
        return Str::limit(Str::slug($proceso->cargo), 120, '').'-'.Str::lower($proceso->codigo);
    }

    private function finDeDia(mixed $fecha): ?Carbon
    {
        return filled($fecha) ? Carbon::parse($fecha)->endOfDay() : null;
    }

    private function evento(Publicacion $publicacion, string $evento, ?string $desde, ?string $hasta, ?int $usuarioId, ?string $detalle = null): void
    {
        PublicacionEvento::create([
            'empresa_id' => $publicacion->empresa_id,
            'publicacion_id' => $publicacion->id,
            'evento' => $evento,
            'estado_desde' => $desde,
            'estado_hasta' => $hasta,
            'usuario_id' => $usuarioId,
            'detalle' => $detalle ? Str::limit($detalle, 255, '') : null,
        ]);
    }

    public function textoBusqueda(ProcesoSeleccion $proceso, Publicacion $publicacion): string
    {
        $area = $publicacion->area_id ? AreaAgento::query()->whereKey($publicacion->area_id)->value('etiqueta') : null;
        // La empresa confidencial nunca entra en el texto buscable.
        $empresa = $proceso->empresa_confidencial ? null : $proceso->empresa()->value('nombre_comercial');

        return Texto::normalizar(implode(' ', array_filter([
            $publicacion->titulo_publico, $proceso->cargo, $publicacion->resumen, $publicacion->ubicacion_texto, $area, $empresa,
        ])));
    }
}
