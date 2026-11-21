<?php

namespace App\Modules\Crm;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Webhook público de Meta: por canal (token de la URL) y, aparte, por IP para URLs inventadas.
        RateLimiter::for('crm-webhook', fn (Request $request) => [
            Limit::perMinute((int) config('crm.webhook_por_minuto', 600))->by('crm-canal:'.sha1((string) $request->route('token'))),
            Limit::perMinute((int) config('crm.webhook_por_minuto', 600) * 2)->by('crm-ip:'.$request->ip()),
        ]);
    }
}
