<?php

namespace App\Modules\Reclutamiento\Console;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use Illuminate\Console\Command;

class CerrarIntentosVencidosCommand extends Command
{
    protected $signature = 'reclutamiento:cerrar-intentos-vencidos
        {--empresa= : Limitar a una empresa (id)}';

    protected $description = 'Cierra intentos de pruebas cuyo tiempo venció y asignaciones fuera de plazo';

    public function handle(AplicacionPruebaService $pruebas): int
    {
        $empresaIds = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : Empresa::query()->pluck('id')->all();

        // Recorrido explícito por empresa: cada una se procesa con su propio contexto.
        $cerrados = 0;
        foreach ($empresaIds as $empresaId) {
            $cerrados += EmpresaContext::como($empresaId, fn () => $pruebas->cerrarVencidos());
        }

        $this->info("Intentos cerrados por tiempo: {$cerrados}");

        return self::SUCCESS;
    }
}
