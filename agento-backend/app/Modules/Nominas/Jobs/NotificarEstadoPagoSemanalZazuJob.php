<?php

namespace App\Modules\Nominas\Jobs;

use App\Modules\Nominas\Models\PagoSemanalLote;
use App\Modules\Nominas\Services\PagoSemanalZazuService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Avisa a plataforma_zazu el estado de un lote de pago semanal (recibido,
 * observado, aprobado, pagado, anulado). En cola: nunca bloquea la acción de
 * Nóminas que lo dispara. Mismo esquema que NotificarEstadoMotorizadoZazuJob
 * (X-Api-Key). Arma el payload al ejecutarse, así siempre manda el último estado.
 */
class NotificarEstadoPagoSemanalZazuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 600, 1800];

    public function __construct(private readonly int $loteId) {}

    public function handle(PagoSemanalZazuService $service): void
    {
        $url = config('services.zazu.planilla_webhook_url');
        $secreto = config('services.zazu.webhook_secret');
        $lote = PagoSemanalLote::find($this->loteId);

        if (! $url || ! $secreto || ! $lote) {
            return;
        }

        $respuesta = Http::withHeaders(['X-Api-Key' => $secreto])->acceptJson()->post($url, $service->payloadNotificacion($lote));

        if ($respuesta->failed()) {
            throw new RuntimeException("plataforma_zazu respondió {$respuesta->status()} al aviso del lote semanal {$lote->referencia}.");
        }
    }

    public function failed(?\Throwable $excepcion): void
    {
        Log::error('Se agotaron los reintentos del aviso de pago semanal a Zazu.', [
            'lote_id' => $this->loteId,
            'excepcion' => $excepcion?->getMessage(),
        ]);
    }
}
