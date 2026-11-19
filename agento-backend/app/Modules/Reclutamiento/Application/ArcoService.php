<?php

namespace App\Modules\Reclutamiento\Application;

use App\Models\User;
use App\Modules\Auditoria\Application\RegistradorAuditoria;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Infrastructure\AlmacenDocumentosCandidato;
use App\Modules\Reclutamiento\Models\AccesoCandidato;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Consentimiento;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\SolicitudArco;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Derechos ARCO del candidato (Ley 29733) y revocación del consentimiento.
 *
 * El candidato se identifica por su correo verificado y puede tener un registro por empresa: cada
 * empresa decide sobre sus propios datos, así que las solicitudes de rectificación y cancelación
 * se registran POR EMPRESA y las atiende su RR. HH. El acceso (exportación) se atiende al instante.
 *
 * Todo queda registrado en Auditoría (acciones `arco.*` y `consentimiento.*`), sin datos personales
 * en el registro: solo identificadores.
 */
class ArcoService
{
    /** Campos que una rectificación puede cambiar (lista cerrada). */
    public const CAMPOS_RECTIFICABLES = [
        'tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular', 'ubicacion', 'resumen',
    ];

    public function __construct(
        private readonly RegistradorAuditoria $auditoria,
        private readonly PostulacionService $postulaciones,
        private readonly RetencionCandidatosService $retencion,
        private readonly AlmacenDocumentosCandidato $documentos,
    ) {}

    // ---- Lado del candidato (sesión verificada por correo) ----

    /**
     * @return Collection<int, Candidato>
     */
    private function candidatosDe(AccesoCandidato $acceso): Collection
    {
        return Candidato::query()->where('email', $acceso->email)->whereNull('anonimizado_at')->get();
    }

    /**
     * Acceso: todos los datos que las empresas conservan del candidato. Sin notas internas, puntajes
     * ni motivos de descarte internos (no son datos aportados por el candidato).
     *
     * @return array<string, mixed>
     */
    public function exportar(AccesoCandidato $acceso, Request $request): array
    {
        $candidatos = $this->candidatosDe($acceso)->load([
            'antecedentes', 'habilidades', 'consentimientos',
            'documentos' => fn ($q) => $q->whereNull('eliminado_at'),
            'postulaciones.proceso:id,empresa_id,codigo,cargo',
        ]);

        $datos = $candidatos->map(function (Candidato $c) use ($request) {
            $this->registrarSolicitud($c, SolicitudArco::ACCESO, ['canal' => 'exportacion automática'], SolicitudArco::ATENDIDA, 'Exportación entregada al titular.');
            $this->auditoria->arco($request, $c->empresa_id, "candidato:{$c->id}", 'arco.acceso', ['candidato_id' => $c->id]);

            return [
                'empresa' => DB::table('empresas')->where('id', $c->empresa_id)->value('nombre_comercial'),
                'perfil' => $c->only(['email', 'tipo_documento', 'numero_documento', 'nombres', 'apellido_paterno', 'apellido_materno', 'celular', 'ubicacion', 'resumen', 'created_at']),
                'experiencias_y_estudios' => $c->antecedentes->map->only(['tipo', 'titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->all(),
                'habilidades' => $c->habilidades->map->only(['nombre', 'nivel'])->all(),
                'documentos' => $c->documentos->map(fn (DocumentoCandidato $d) => [
                    'id' => $d->id, 'tipo' => $d->tipo, 'nombre' => $d->nombre_original, 'mime' => $d->mime, 'tamano' => $d->tamano, 'subido_at' => $d->created_at,
                ])->all(),
                'postulaciones' => $c->postulaciones->map(fn (Postulacion $p) => [
                    'codigo_seguimiento' => $p->codigo_seguimiento,
                    'proceso' => $p->proceso?->codigo,
                    'cargo' => $p->proceso?->cargo,
                    'estado' => $p->estado,
                    'fecha' => $p->created_at,
                ])->all(),
                'consentimientos' => $c->consentimientos->map->only(['version', 'aceptado_at', 'revocado_at'])->all(),
            ];
        })->all();

        return ['generado_at' => now()->toIso8601String(), 'correo' => $acceso->email, 'empresas' => $datos];
    }

    public function descargarDocumento(AccesoCandidato $acceso, int $documentoId, Request $request): StreamedResponse
    {
        $documento = DocumentoCandidato::query()
            ->whereKey($documentoId)->whereNull('eliminado_at')
            ->whereHas('candidato', fn ($q) => $q->where('email', $acceso->email))
            ->first();
        if (! $documento) {
            throw new NotFoundHttpException;
        }

        $this->auditoria->arco($request, $documento->empresa_id, "candidato:{$documento->candidato_id}", 'arco.acceso_documento', ['documento_id' => $documento->id]);

        return $this->documentos->descargar($documento);
    }

    /**
     * Solicitud de rectificación o cancelación: una por empresa que conserve datos del candidato.
     * Si ya hay una abierta del mismo tipo para ese candidato, se devuelve esa (idempotente).
     *
     * @param  array<string, mixed>  $detalle
     * @return Collection<int, SolicitudArco>
     */
    public function solicitar(AccesoCandidato $acceso, string $tipo, array $detalle, Request $request): Collection
    {
        $solicitudes = $this->candidatosDe($acceso)->map(function (Candidato $c) use ($tipo, $detalle, $request) {
            $abierta = SolicitudArco::query()->where('candidato_id', $c->id)->where('tipo', $tipo)
                ->whereIn('estado', [SolicitudArco::PENDIENTE, SolicitudArco::EN_PROCESO])->first();
            if ($abierta) {
                return $abierta;
            }

            $solicitud = $this->registrarSolicitud($c, $tipo, $detalle);
            $this->auditoria->arco($request, $c->empresa_id, "candidato:{$c->id}", "arco.{$tipo}_solicitada", ['solicitud_id' => $solicitud->id]);

            return $solicitud;
        });

        if ($solicitudes->isEmpty()) {
            throw ValidationException::withMessages(['correo' => 'No hay datos asociados a este correo.']);
        }

        return $solicitudes->values();
    }

    /**
     * @return Collection<int, SolicitudArco>
     */
    public function solicitudesDe(AccesoCandidato $acceso): Collection
    {
        return SolicitudArco::query()
            ->whereHas('candidato', fn ($q) => $q->where('email', $acceso->email))
            ->latest('id')->get();
    }

    /**
     * Revoca el consentimiento: se marcan revocados los consentimientos, se retiran las postulaciones
     * en curso y se registra una solicitud de cancelación por empresa para que RR. HH. elimine los
     * datos. No se pueden eliminar los datos de quien tiene una contratación (relación laboral): esa
     * empresa conserva la solicitud para su evaluación.
     *
     * @return array{empresas: int, postulaciones_retiradas: int}
     */
    public function revocarConsentimiento(AccesoCandidato $acceso, Request $request): array
    {
        $retiradas = 0;
        $candidatos = $this->candidatosDe($acceso);

        foreach ($candidatos as $c) {
            DB::transaction(function () use ($c, $request, &$retiradas) {
                Consentimiento::query()->where('candidato_id', $c->id)->whereNull('revocado_at')->update(['revocado_at' => now()]);

                Postulacion::query()->where('candidato_id', $c->id)
                    ->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA])->get()
                    ->each(function (Postulacion $p) use (&$retiradas) {
                        $this->postulaciones->retirar($p, 'Revocación del consentimiento por el candidato', null);
                        $retiradas++;
                    });

                $existe = SolicitudArco::query()->where('candidato_id', $c->id)->where('tipo', SolicitudArco::CANCELACION)
                    ->whereIn('estado', [SolicitudArco::PENDIENTE, SolicitudArco::EN_PROCESO])->exists();
                if (! $existe) {
                    $this->registrarSolicitud($c, SolicitudArco::CANCELACION, ['origen' => 'revocación del consentimiento']);
                }

                $this->auditoria->arco($request, $c->empresa_id, "candidato:{$c->id}", 'consentimiento.revocado', ['candidato_id' => $c->id]);
            });
        }

        return ['empresas' => $candidatos->count(), 'postulaciones_retiradas' => $retiradas];
    }

    // ---- Lado de RR. HH. (panel interno) ----

    public function listar(array $filtros, int $perPage): LengthAwarePaginator
    {
        return SolicitudArco::query()
            ->with('candidato:id,nombres,apellido_paterno,apellido_materno,email,numero_documento')
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when(! empty($filtros['vencidas']), fn ($q) => $q->whereIn('estado', [SolicitudArco::PENDIENTE, SolicitudArco::EN_PROCESO])->where('vence_at', '<', now()))
            ->orderByRaw("case when estado in ('pendiente','en_proceso') then 0 else 1 end")
            ->orderBy('vence_at')
            ->paginate($perPage);
    }

    /**
     * Atiende una solicitud abierta. Al marcar una rectificación como atendida se aplican los cambios
     * solicitados; al marcar una cancelación como atendida se anonimizan los datos (salvo contratados).
     */
    public function atender(SolicitudArco $solicitud, string $estado, ?string $respuesta, User $usuario, Request $request): SolicitudArco
    {
        if (! $solicitud->estaAbierta()) {
            throw ValidationException::withMessages(['estado' => 'La solicitud ya fue cerrada.']);
        }
        if ($estado === SolicitudArco::RECHAZADA && blank($respuesta)) {
            throw ValidationException::withMessages(['respuesta' => 'Indica el motivo del rechazo.']);
        }

        return DB::transaction(function () use ($solicitud, $estado, $respuesta, $usuario, $request) {
            $candidato = $solicitud->candidato()->firstOrFail();

            if ($estado === SolicitudArco::ATENDIDA) {
                match ($solicitud->tipo) {
                    SolicitudArco::RECTIFICACION => $this->aplicarRectificacion($candidato, (array) ($solicitud->detalle['cambios'] ?? [])),
                    SolicitudArco::CANCELACION => $this->ejecutarCancelacion($candidato),
                    default => null,
                };
            }

            $cerrada = in_array($estado, [SolicitudArco::ATENDIDA, SolicitudArco::RECHAZADA], true);
            $solicitud->update([
                'estado' => $estado,
                'respuesta' => $respuesta,
                'atendida_at' => $cerrada ? now() : null,
                'atendida_por' => $cerrada ? $usuario->id : null,
            ]);

            $this->auditoria->arco($request, $solicitud->empresa_id, null, "arco.{$solicitud->tipo}_{$estado}", ['solicitud_id' => $solicitud->id], $usuario);

            return $solicitud->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function aplicarRectificacion(Candidato $candidato, array $cambios): void
    {
        $permitidos = array_intersect_key($cambios, array_flip(self::CAMPOS_RECTIFICABLES));
        if ($permitidos === []) {
            throw ValidationException::withMessages(['estado' => 'La solicitud no trae cambios aplicables.']);
        }

        $candidato->update($permitidos);
    }

    private function ejecutarCancelacion(Candidato $candidato): void
    {
        $contratado = Postulacion::query()->where('candidato_id', $candidato->id)->where('estado', FlujoPostulacion::CONTRATADA)->exists();
        if ($contratado) {
            throw ValidationException::withMessages(['estado' => 'No se pueden eliminar los datos de una persona contratada mientras exista la relación laboral. Recházala indicando el motivo.']);
        }

        $this->retencion->anonimizar($candidato);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    private function registrarSolicitud(Candidato $candidato, string $tipo, array $detalle, string $estado = SolicitudArco::PENDIENTE, ?string $respuesta = null): SolicitudArco
    {
        $cerrada = $estado === SolicitudArco::ATENDIDA;

        return SolicitudArco::create([
            'empresa_id' => $candidato->empresa_id,
            'candidato_id' => $candidato->id,
            'tipo' => $tipo,
            'estado' => $estado,
            'detalle' => $detalle,
            'respuesta' => $respuesta,
            'solicitada_at' => now(),
            'vence_at' => now()->addWeekdays((int) config("reclutamiento.arco.plazo_dias_habiles.{$tipo}", 10)),
            'atendida_at' => $cerrada ? now() : null,
        ]);
    }
}
