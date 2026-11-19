<?php

namespace App\Modules\Reclutamiento\Infrastructure\Antivirus;

use App\Modules\Reclutamiento\Domain\Contracts\EscanerArchivos;
use App\Modules\Reclutamiento\Domain\ResultadoEscaneo;

/**
 * Escáner para local y testing: NO hay motor antivirus. Da por limpio todo salvo la cadena de
 * prueba EICAR, que reconoce para poder ejercitar el flujo de cuarentena sin ClamAV.
 *
 * Fuera de local/testing responde "no disponible": un entorno real sin ClamAV nunca debe dar por
 * limpio un archivo (falla cerrado, ver reclutamiento.antivirus.si_no_disponible).
 */
class EscanerNulo implements EscanerArchivos
{
    /** Cadena estándar de prueba EICAR (inocua; todos los antivirus la detectan por convención). */
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function escanear($contenido): ResultadoEscaneo
    {
        if (! app()->environment(['local', 'testing'])) {
            return ResultadoEscaneo::noDisponible('Sin motor antivirus configurado (ANTIVIRUS_DRIVER=nulo) fuera de local/testing.');
        }

        $muestra = (string) stream_get_contents($contenido, 10 * 1024 * 1024);

        return str_contains($muestra, self::EICAR)
            ? ResultadoEscaneo::infectado('Eicar-Test-Signature')
            : ResultadoEscaneo::limpio();
    }
}
