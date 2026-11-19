<?php

namespace App\Providers;

use App\Modules\Configuracion\Support\EmpresaContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Propaga el contexto de empresa a los jobs.
 *
 * Al encolar, la empresa del contexto actual se guarda en el payload del job
 * (`empresa_contexto`). Al ejecutarlo, el worker la restaura y al terminar vuelve al
 * estado anterior. Así un job despachado desde una petición de la empresa A nunca corre
 * sin contexto ni con el de otra empresa, sin cambiar cada job. Un job despachado sin
 * contexto de empresa (p. ej. desde el modo sistema) no hereda ninguna: si necesita
 * empresa debe establecerla él mismo.
 */
class TenantServiceProvider extends ServiceProvider
{
    /** @var array<int, array{0: ?int, 1: bool, 2: ?string}> contexto previo por job en ejecución */
    private static array $previos = [];

    public function boot(): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            $empresaId = EmpresaContext::actual();

            return $empresaId ? ['empresa_contexto' => $empresaId] : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $evento) {
            $empresaId = $evento->job->payload()['empresa_contexto'] ?? null;
            if ($empresaId) {
                self::$previos[spl_object_id($evento->job)] = EmpresaContext::entrarEmpresa((int) $empresaId);
            }
        });

        $restaurar = function (JobProcessed|JobFailed $evento) {
            $id = spl_object_id($evento->job);
            if (isset(self::$previos[$id])) {
                EmpresaContext::restaurar(self::$previos[$id]);
                unset(self::$previos[$id]);
            }
        };
        Event::listen(JobProcessed::class, $restaurar);
        Event::listen(JobFailed::class, $restaurar);
    }
}
