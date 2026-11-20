<?php

namespace App\Modules\PortalAgento\Http\Resources;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\PerfilRequisito;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Job del contrato openapi.yaml (GET /jobs, /jobs/{slug}). LISTA BLANCA de campos: nada del ATS, del
 * solicitante, del reclutador, de las áreas internas ni de la empresa cuando es confidencial.
 *
 * Extensiones no contractuales (el cliente zod las ignora): status, acceptsApplications, publicMessage,
 * jobCode y confidential. Ver docs/agento/brechas-contrato.md.
 *
 * @mixin Publicacion
 */
class OfertaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProcesoSeleccion $proceso */
        $proceso = $this->proceso;
        $perfil = $proceso->perfilVigente;
        $requisitos = $perfil?->requisitos ?? collect();
        $confidencial = (bool) $proceso->empresa_confidencial;
        $pausada = $this->estado_publicacion === Publicacion::PAUSADA || $proceso->estado === ProcesoSeleccion::EN_PAUSA;
        $funciones = $this->parrafos($proceso->solicitud?->actividades, true);
        $acepta = ! $pausada && $this->estado_publicacion === Publicacion::PUBLICADA && $proceso->aceptaPostulaciones();

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->titulo_publico ?: $proceso->cargo,
            'company' => EmpresaResumenResource::datos($this->resource),
            'location' => [
                'departamento' => $this->ubigeo?->departamento ?? '',
                'provincia' => $this->ubigeo?->provincia ?? '',
                'distrito' => $this->ubigeo?->distrito ?? '',
            ],
            'area' => $this->area?->etiqueta,
            'level' => $this->nivel?->clave,
            'schedule' => $this->jornada?->clave,
            'mode' => $proceso->modalidad,
            'salary' => $this->sueldo($proceso),
            'postedAt' => $this->publicada_at?->toIso8601String(),
            'expiresAt' => $this->vencimiento($proceso)?->toIso8601String(),
            'noExperience' => (bool) $this->sin_experiencia,
            'inclusive' => (bool) $this->inclusiva,
            'benefits' => $this->beneficios->pluck('etiqueta')->values()->all(),
            'requirements' => [
                'mandatory' => $requisitos->where('tipo', PerfilRequisito::EXCLUYENTE)->pluck('descripcion')->values()->all(),
                'desirable' => $requisitos->where('tipo', PerfilRequisito::DESEABLE)->pluck('descripcion')->values()->all(),
            ],
            'summary' => $this->resumen ?: Str::limit(trim((string) $proceso->descripcion), 200),
            'description' => $this->sinFunciones($this->parrafos($proceso->descripcion), $funciones),
            'responsibilities' => $funciones,
            'questions' => [],

            // Extensiones.
            'status' => $pausada ? 'pausada' : 'activa',
            'acceptsApplications' => $acepta,
            'publicMessage' => $acepta ? null : $proceso->mensaje_publico,
            'jobCode' => $proceso->codigo,
            'confidential' => $confidencial,
        ];
    }

    /**
     * El contrato solo admite soles y un rango completo: otra moneda o un sueldo no visible → null
     * («Sueldo no informado»). Si solo hay un monto, el mínimo y el máximo coinciden.
     *
     * @return array{min: float, max: float}|null
     */
    private function sueldo(ProcesoSeleccion $proceso): ?array
    {
        if (! $proceso->mostrar_salario || $proceso->moneda !== 'PEN' || $proceso->salario_min === null) {
            return null;
        }
        $min = (float) $proceso->salario_min;

        return ['min' => $min, 'max' => (float) ($proceso->salario_max ?? $min)];
    }

    private function vencimiento(ProcesoSeleccion $proceso): ?\Illuminate\Support\Carbon
    {
        $cierre = $proceso->fecha_cierre_postulacion?->copy()->endOfDay();
        $vigente = $this->vigente_hasta;

        return match (true) {
            $cierre && $vigente => $cierre->min($vigente),
            default => $cierre ?? $vigente,
        };
    }

    /**
     * El texto inicial del aviso trae las funciones dentro de la descripción («Funciones principales» + lista).
     * Como el contrato las entrega aparte en `responsibilities`, aquí se quitan para no mostrarlas dos veces.
     *
     * @param  list<string>  $parrafos
     * @param  list<string>  $funciones
     * @return list<string>
     */
    private function sinFunciones(array $parrafos, array $funciones): array
    {
        if ($funciones === []) {
            return $parrafos;
        }
        $quitar = array_merge(['Funciones principales'], $funciones);

        return array_values(array_filter($parrafos, fn (string $p) => ! in_array($p, $quitar, true)));
    }

    /**
     * @return list<string>
     */
    private function parrafos(?string $texto, bool $quitarViñetas = false): array
    {
        $lineas = preg_split('/\R+/', trim((string) $texto)) ?: [];
        $lineas = array_map(fn (string $l) => trim($quitarViñetas ? (string) preg_replace('/^[\-\*•\x{2022}\d\.\)\s]+/u', '', $l) : $l), $lineas);

        return array_values(array_filter($lineas, fn (string $l) => $l !== ''));
    }
}
