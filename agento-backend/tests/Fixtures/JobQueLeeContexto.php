<?php

namespace Tests\Fixtures;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Reclutamiento\Models\Candidato;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Job de prueba: registra el contexto de empresa con el que se ejecuta y cuántos candidatos ve.
 */
class JobQueLeeContexto implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?int $empresaVista = null;

    public static ?int $candidatosVistos = null;

    public static function reiniciar(): void
    {
        self::$empresaVista = null;
        self::$candidatosVistos = null;
    }

    public function handle(): void
    {
        self::$empresaVista = EmpresaContext::actual();
        self::$candidatosVistos = Candidato::query()->count();
    }
}
