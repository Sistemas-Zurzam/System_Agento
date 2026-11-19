<?php

namespace App\Modules\Reclutamiento\Domain\Contracts;

use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;

/**
 * Puerto del antivirus. Implementaciones: ClamAV (producción) y una nula para local y testing.
 * Nunca debe lanzar excepciones por fallos del motor: devuelve ResultadoEscaneo::noDisponible().
 */
interface EscanerArchivos
{
    /**
     * @param  resource  $contenido  flujo legible con el contenido del archivo
     */
    public function escanear($contenido): ResultadoEscaneo;
}
