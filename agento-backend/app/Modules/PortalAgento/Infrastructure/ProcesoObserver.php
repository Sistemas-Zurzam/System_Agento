<?php

namespace App\Modules\PortalAgento\Infrastructure;

use App\Modules\PortalAgento\Application\SincronizadorPublicaciones;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;

/**
 * Cuando cambia un proceso, su publicación en Agento se alinea (pausa, cierre, retiro, texto buscable).
 * Solo reacciona a campos que afectan a lo publicado; el resto de cambios del proceso no hace nada.
 */
class ProcesoObserver
{
    private const CAMPOS = [
        'estado', 'retirada_at', 'publicacion_externa', 'cargo', 'descripcion', 'modalidad', 'ubicacion',
        'salario_min', 'salario_max', 'moneda', 'mostrar_salario', 'empresa_confidencial', 'fecha_cierre_postulacion',
        'mensaje_publico',
    ];

    public function __construct(private readonly SincronizadorPublicaciones $sincronizador) {}

    public function updated(ProcesoSeleccion $proceso): void
    {
        if ($proceso->wasChanged(self::CAMPOS)) {
            $this->sincronizador->sincronizar($proceso);
        }
    }
}
