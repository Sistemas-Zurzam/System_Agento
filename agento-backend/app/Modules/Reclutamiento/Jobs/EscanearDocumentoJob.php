<?php

namespace App\Modules\Reclutamiento\Jobs;

use App\Modules\Reclutamiento\Application\EscaneoDocumentos;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Escanea un documento de candidato con el antivirus. Recibe la empresa resuelta por el servidor y
 * busca el documento acotado a ella. Si el antivirus no responde se reintenta con espera creciente;
 * agotados los intentos el documento queda `error_escaneo` (sigue sin poder descargarse) y el comando
 * programado `reclutamiento:escanear-documentos` lo vuelve a intentar.
 */
class EscanearDocumentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        private readonly int $empresaId,
        private readonly int $documentoId,
    ) {}

    public function handle(EscaneoDocumentos $escaneo): void
    {
        $documento = $this->documento();

        if (! $documento || ! in_array($documento->estado_escaneo, DocumentoCandidato::POR_ESCANEAR, true)) {
            return;
        }

        $escaneo->procesar($documento);
    }

    public function failed(Throwable $e): void
    {
        $documento = $this->documento();
        if ($documento && in_array($documento->estado_escaneo, DocumentoCandidato::POR_ESCANEAR, true)) {
            app(EscaneoDocumentos::class)->marcarError($documento, $e->getMessage());
        }
    }

    private function documento(): ?DocumentoCandidato
    {
        return DocumentoCandidato::withoutGlobalScopes()
            ->where('empresa_id', $this->empresaId)
            ->whereKey($this->documentoId)
            ->first();
    }
}
