<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Application\TextosLegalesService;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\TextoLegal;

/**
 * Lista de verificación previa a publicar en Agento. Cada ítem es obligatorio (bloquea) o informativo.
 */
class ChecklistPublicacion
{
    public function __construct(private readonly TextosLegalesService $textosLegales) {}

    /**
     * @return array{items: list<array{clave: string, etiqueta: string, ok: bool, obligatorio: bool, detalle: ?string}>, listo: bool}
     */
    public function evaluar(ProcesoSeleccion $proceso, ?Publicacion $publicacion): array
    {
        $proceso->loadMissing(['perfilVigente.competencias', 'empresa']);
        $perfil = $proceso->perfilVigente;
        $plazasLibres = $proceso->plazas()->where('estado', Plaza::DISPONIBLE)->count();
        $hoy = now()->toDateString();

        $items = [
            $this->item('proceso', 'El proceso está en borrador, abierto o en pausa', in_array($proceso->estado, [ProcesoSeleccion::BORRADOR, ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA], true), true),
            $this->item('empresa', 'La empresa está activa y tiene el servicio de reclutamiento', (bool) ($proceso->empresa?->activa && $proceso->empresa?->servicio_reclutamiento), true),
            $this->item('perfil', 'El perfil tiene al menos una competencia', (bool) ($perfil && $perfil->competencias->isNotEmpty()), true),
            $this->item('plazas', 'Hay plazas disponibles', $plazasLibres > 0, true, "{$plazasLibres} disponible(s)"),
            $this->item('descripcion', 'El proceso tiene descripción del puesto', filled($proceso->descripcion), true),
            $this->item('resumen', 'Resumen público de al menos 20 caracteres', mb_strlen(trim((string) $publicacion?->resumen)) >= 20, true),
            $this->item('area', 'Área seleccionada', $publicacion?->area_id !== null, true),
            $this->item('nivel', 'Nivel seleccionado', $publicacion?->nivel_id !== null, true),
            $this->item('jornada', 'Jornada seleccionada', $publicacion?->jornada_id !== null, true),
            $this->item('ubicacion', 'Ubicación (distrito) seleccionada', filled($publicacion?->ubigeo_codigo), true),
            $this->item('vigencia', 'La fecha de cierre y la vigencia no han vencido',
                ($proceso->fecha_cierre_postulacion === null || $proceso->fecha_cierre_postulacion->toDateString() >= $hoy)
                && ($publicacion?->vigente_hasta === null || $publicacion->vigente_hasta->isFuture()), true),
            $this->item('consentimiento', 'Existe un texto de consentimiento vigente', $this->textosLegales->vigente(TextoLegal::CONSENTIMIENTO_POSTULACION) !== null, true),
            $this->item('sueldo', 'Sueldo visible para el candidato', (bool) $proceso->mostrar_salario && $proceso->salario_min !== null, false,
                $proceso->mostrar_salario ? null : 'Se mostrará «Sueldo no informado»'),
        ];

        $listo = collect($items)->every(fn (array $item) => ! $item['obligatorio'] || $item['ok']);

        return ['items' => $items, 'listo' => $listo];
    }

    /**
     * @return array{clave: string, etiqueta: string, ok: bool, obligatorio: bool, detalle: ?string}
     */
    private function item(string $clave, string $etiqueta, bool $ok, bool $obligatorio, ?string $detalle = null): array
    {
        return compact('clave', 'etiqueta', 'ok', 'obligatorio', 'detalle');
    }
}
