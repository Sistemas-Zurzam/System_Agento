<?php

namespace App\Modules\Reclutamiento\Console;

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

        // Recorre todas las empresas a propósito (tarea programada); cada job lleva su empresa.
        $documentos = DocumentoCandidato::withoutGlobalScopes()
            ->whereIn('estado_escaneo', $estados)
            ->whereNull('eliminado_at')->whereNotNull('ruta')
            ->orderBy('id')->limit(max(1, (int) $this->option('limite')))
            ->get(['id', 'empresa_id']);

        foreach ($documentos as $documento) {
            EscanearDocumentoJob::dispatch($documento->empresa_id, $documento->id);
        }

        $this->info("Documentos encolados para escaneo: {$documentos->count()}");

        return self::SUCCESS;
    }
}
