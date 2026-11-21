<?php

namespace App\Modules\Reclutamiento\Console;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Reclutamiento\Application\RetencionCandidatosService;
use Illuminate\Console\Command;

/**
 * Vencimiento del banco de talentos. Por defecto es una EJECUCIÓN DE PRUEBA:
 * solo informa cuántos candidatos vencerían. La anonimización irreversible
 * exige ambas condiciones: --ejecutar Y RECLUTAMIENTO_RETENCION_ELIMINAR=true.
 */
class VencerCandidatosCommand extends Command
{
    protected $signature = 'reclutamiento:vencer-candidatos
        {--ejecutar : Anonimiza de forma irreversible (requiere además la configuración habilitada)}
        {--empresa= : Limitar a una empresa (id)}';

    protected $description = 'Anonimiza candidaturas cuya retención venció (ejecución de prueba por defecto)';

    public function handle(RetencionCandidatosService $retencion): int
    {
        // Recorrido explícito por empresa: cada una se procesa con su propio contexto.
        $empresaIds = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : Empresa::query()->pluck('id')->all();
        $total = array_sum(array_map(fn (int $id) => $retencion->candidatosVencidos($id)->count(), $empresaIds));
        $meses = (int) config('reclutamiento.retencion.meses', 24);

        $this->info("Candidaturas vencidas (más de {$meses} meses desde la última postulación): {$total}");

        $habilitado = (bool) config('reclutamiento.retencion.eliminacion_habilitada', false);
        if (! $this->option('ejecutar') || ! $habilitado) {
            $this->warn('Ejecución de prueba: no se modificó ningún dato.'
                .($this->option('ejecutar') && ! $habilitado ? ' Activa RECLUTAMIENTO_RETENCION_ELIMINAR=true para ejecutar.' : ''));

            return self::SUCCESS;
        }

        $procesados = 0;
        foreach ($empresaIds as $empresaId) {
            EmpresaContext::como($empresaId, function () use ($retencion, $empresaId, &$procesados) {
                $retencion->candidatosVencidos($empresaId)->orderBy('id')->chunkById(100, function ($candidatos) use ($retencion, &$procesados) {
                    foreach ($candidatos as $candidato) {
                        $retencion->anonimizar($candidato);
                        $procesados++;
                    }
                });
            });
        }

        $this->info("Candidaturas anonimizadas: {$procesados}");

        return self::SUCCESS;
    }
}
