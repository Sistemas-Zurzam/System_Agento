<?php

namespace App\Modules\Integraciones;

use App\Modules\Integraciones\Console\CrearCredencialIntegracionCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class IntegracionesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CrearCredencialIntegracionCommand::class]);
        }

        // Por IP, y aparte por token (hash) para que un solo cliente no agote el cupo de otros.
        RateLimiter::for('integraciones', fn (Request $request) => [
            Limit::perMinute((int) config('integraciones.limite_por_minuto', 60))->by('integ-ip:'.$request->ip()),
            Limit::perMinute((int) config('integraciones.limite_por_minuto', 60))->by('integ-key:'.sha1((string) $request->header('X-Api-Key'))),
        ]);
    }
}
