<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->definirLimitadores();
    }

    /**
     * Límites de tasa de autenticación y del portal público. Los del módulo de
     * reclutamiento (códigos, verificación, publico) viven en su provider.
     * Al excederse responden 429 con Retry-After y un mensaje que no revela
     * si la cuenta existe.
     */
    private function definirLimitadores(): void
    {
        $intentos = fn (Request $request, array $headers) => response()->json(
            ['message' => 'Demasiados intentos. Espera un momento antes de volver a intentarlo.'],
            429,
            $headers,
        );

        RateLimiter::for('login', function (Request $request) use ($intentos) {
            $login = mb_strtolower(trim((string) $request->input('login')));

            return [
                Limit::perMinute((int) config('seguridad.login.por_minuto_login_ip'))
                    ->by('login:'.hash('sha256', $login.'|'.$request->ip()))->response($intentos),
                Limit::perMinute((int) config('seguridad.login.por_minuto_ip'))
                    ->by('login-ip:'.$request->ip())->response($intentos),
            ];
        });

        // Por usuario autenticado (el middleware jwt corre antes): varios usuarios tras una misma IP
        // no se pisan y renovar el token no reinicia el contador. Sin usuario, por IP.
        $porSesion = fn (Request $request) => ($id = auth('api')->id())
            ? 'user:'.$id
            : 'ip:'.$request->ip();

        RateLimiter::for('auth-refresh', fn (Request $request) => Limit::perMinute((int) config('seguridad.sesion.refresh_por_minuto'))
            ->by($porSesion($request))->response($intentos));

        RateLimiter::for('auth-me', fn (Request $request) => Limit::perMinute((int) config('seguridad.sesion.me_por_minuto'))
            ->by($porSesion($request))->response($intentos));

        RateLimiter::for('portal-listado', fn (Request $request) => Limit::perMinute((int) config('seguridad.portal.listado_por_minuto'))
            ->by('listado:'.$request->ip())->response($intentos));

        RateLimiter::for('portal-detalle', fn (Request $request) => Limit::perMinute((int) config('seguridad.portal.detalle_por_minuto'))
            ->by('detalle:'.$request->ip())->response($intentos));
    }
}
