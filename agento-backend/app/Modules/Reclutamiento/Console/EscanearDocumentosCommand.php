<?php

namespace App\Modules\Reclutamiento\Console;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Reclutamiento\Jobs\EscanearDocumentoJob;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use Illuminate\Console\Command;

/**
 * Encola el escaneo de los documentos que quedaron sin escanear: nuevos pendientes, con error de
 * escaneo (el antivirus no respondió) y, con --legado, los subidos antes de existir el antivirus.
 */
class EscanearDocumentosCommand extends Command
{
    protected $signature = 'reclutamiento:escanear-documentos
        {--legado : Incluye los documentos anteriores al antivirus (hoy descargables sin escanear)}
        {--limite=200 : Máximo de documentos a encolar}';

    protected $description = 'Encola el escaneo antivirus de documentos de candidatos pendientes, con error o legados';

    public function handle(): int
    {
        $estados = [DocumentoCandidato::PENDIENTE_ESCANEO, DocumentoCandidato::ERROR_ESCANEO];
        if ($this->option('legado')) {
            $estados[] = DocumentoCandidato::LEGADO;
        }

        // Recorrido explícito por empresa (tarea programada): cada una con su propio contexto, y cada job
        // lleva su empresa. El límite es global.
        $restantes = max(1, (int) $this->option('limite'));
        $encolados = 0;

        foreach (Empresa::query()->orderBy('id')->pluck('id') as $empresaId) {
            if ($restantes <= 0) {
                break;
            }

            $ids = EmpresaContext::como($empresaId, fn () => DocumentoCandidato::query()
                ->whereIn('estado_escaneo', $estados)
                ->whereNull('eliminado_at')->whereNotNull('ruta')
                ->orderBy('id')->limit($restantes)
                ->pluck('id'));

            foreach ($ids as $documentoId) {
                EscanearDocumentoJob::dispatch($empresaId, $documentoId);
            }

            $encolados += $ids->count();
            $restantes -= $ids->count();
        }

        $this->info("Documentos encolados para escaneo: {$encolados}");

        return self::SUCCESS;
    }
}
