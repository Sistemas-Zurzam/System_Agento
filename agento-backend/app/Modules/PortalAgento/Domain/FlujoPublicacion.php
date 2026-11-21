<?php

namespace App\Modules\PortalAgento\Domain;

use App\Modules\PortalAgento\Models\Publicacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Validation\ValidationException;

/**
 * Máquina de estados de la publicación en Agento (ver docs/agento/flujo-publicacion.md).
 * Pura: no toca la base de datos.
 */
final class FlujoPublicacion
{
    public const ACCIONES = ['publicar', 'programar', 'pausar', 'reanudar', 'retirar', 'renovar'];

    /** @var array<string, list<string>> acción => estados de origen permitidos */
    private const ORIGENES = [
        'publicar' => [Publicacion::BORRADOR, Publicacion::PROGRAMADA],
        'programar' => [Publicacion::BORRADOR, Publicacion::PROGRAMADA],
        'pausar' => [Publicacion::PUBLICADA],
        'reanudar' => [Publicacion::PAUSADA],
        'retirar' => [Publicacion::PROGRAMADA, Publicacion::PUBLICADA, Publicacion::PAUSADA],
        'renovar' => [Publicacion::PUBLICADA, Publicacion::PAUSADA, Publicacion::VENCIDA, Publicacion::RETIRADA],
    ];

    /** @var array<string, string> */
    private const DESTINOS = [
        'publicar' => Publicacion::PUBLICADA,
        'programar' => Publicacion::PROGRAMADA,
        'pausar' => Publicacion::PAUSADA,
        'reanudar' => Publicacion::PUBLICADA,
        'retirar' => Publicacion::RETIRADA,
        'renovar' => Publicacion::PUBLICADA,
    ];

    public static function puede(string $estado, string $accion): bool
    {
        return in_array($estado, self::ORIGENES[$accion] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public static function accionesDisponibles(string $estado): array
    {
        return array_values(array_filter(self::ACCIONES, fn (string $accion) => self::puede($estado, $accion)));
    }

    public static function aplicar(string $estado, string $accion): string
    {
        if (! self::puede($estado, $accion)) {
            throw ValidationException::withMessages(['accion' => "No se puede {$accion} una publicación en estado «{$estado}»."]);
        }

        return self::DESTINOS[$accion];
    }

    /**
     * Estado de la publicación que corresponde a un proceso, o null si no hay que cambiarla.
     * Es la regla de sincronización: la usan el observer del proceso y el comando programado.
     *
     * @return array{estado: string, pausada_por: ?string, motivo: ?string}|null
     */
    public static function estadoSegunProceso(Publicacion $publicacion, ProcesoSeleccion $proceso, \DateTimeInterface $ahora): ?array
    {
        $estado = $publicacion->estado_publicacion;
        $vivo = in_array($estado, [Publicacion::PUBLICADA, Publicacion::PAUSADA], true);

        // Programada: sale sola a la hora indicada si el proceso sigue abierto.
        if ($estado === Publicacion::PROGRAMADA) {
            if ($publicacion->programada_para !== null && $publicacion->programada_para <= $ahora
                && $proceso->estado === ProcesoSeleccion::ABIERTO) {
                return ['estado' => Publicacion::PUBLICADA, 'pausada_por' => null, 'motivo' => null];
            }

            return null;
        }

        if (! $vivo) {
            return null;
        }

        if (in_array($proceso->estado, [ProcesoSeleccion::CUBIERTO, ProcesoSeleccion::CERRADO, ProcesoSeleccion::CANCELADO], true)) {
            return ['estado' => Publicacion::RETIRADA, 'pausada_por' => null, 'motivo' => "proceso_{$proceso->estado}"];
        }

        if ($proceso->retirada_at !== null || ! $proceso->publicacion_externa) {
            return ['estado' => Publicacion::RETIRADA, 'pausada_por' => null, 'motivo' => 'publicacion_retirada_del_proceso'];
        }

        $vencida = ($publicacion->vigente_hasta !== null && $publicacion->vigente_hasta <= $ahora)
            || ($proceso->fecha_cierre_postulacion !== null && $proceso->fecha_cierre_postulacion->copy()->endOfDay() <= $ahora);
        if ($vencida) {
            return ['estado' => Publicacion::VENCIDA, 'pausada_por' => null, 'motivo' => 'vigencia_cumplida'];
        }

        if ($proceso->estado === ProcesoSeleccion::EN_PAUSA && $estado === Publicacion::PUBLICADA) {
            return ['estado' => Publicacion::PAUSADA, 'pausada_por' => 'proceso', 'motivo' => 'proceso_en_pausa'];
        }

        // Solo se reanuda sola la pausa que provocó el proceso; la pausa manual en Agento la levanta una persona.
        if ($proceso->estado === ProcesoSeleccion::ABIERTO && $estado === Publicacion::PAUSADA && $publicacion->pausada_por === 'proceso') {
            return ['estado' => Publicacion::PUBLICADA, 'pausada_por' => null, 'motivo' => 'proceso_reanudado'];
        }

        return null;
    }
}
