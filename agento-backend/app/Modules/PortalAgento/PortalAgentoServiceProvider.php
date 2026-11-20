<?php

namespace App\Modules\PortalAgento;

use App\Modules\PortalAgento\Console\SincronizarPublicacionesCommand;
use App\Modules\PortalAgento\Http\Middleware\RespuestaApiV1;
use App\Modules\PortalAgento\Infrastructure\ProcesoObserver;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PortalAgentoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Application\SincronizadorPublicaciones::class);
    }

    public function boot(): void
    {
        // La publicación en Agento sigue al proceso (pausa, cierre, retiro) sin tocar el módulo Reclutamiento.
        ProcesoSeleccion::observe(ProcesoObserver::class);

        // RespuestaApiV1 debe envolver a throttle: sin esto Laravel ordena throttle antes y su 429 no llega
        // al formato { code, message, details }.
        $kernel = $this->app->make(HttpKernel::class);
        if ($kernel instanceof FoundationKernel) {
            $kernel->prependToMiddlewarePriority(RespuestaApiV1::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SincronizarPublicacionesCommand::class]);
        }

        // API pública /api/v1: por IP. La búsqueda tiene un límite propio más estricto.
        RateLimiter::for('agento-publico', fn (Request $request) => Limit::perMinute((int) config('portal_agento.limite_por_minuto', 120))->by('ip:'.$request->ip()));
        RateLimiter::for('agento-busqueda', fn (Request $request) => Limit::perMinute((int) config('portal_agento.limite_busqueda_por_minuto', 60))->by('ip:'.$request->ip()));
    }
}
