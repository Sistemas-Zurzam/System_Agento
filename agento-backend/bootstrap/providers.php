<?php

use App\Modules\Auditoria\AuditoriaServiceProvider;
use App\Modules\Reclutamiento\ReclutamientoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TenantServiceProvider;

return [
    AppServiceProvider::class,
    AuditoriaServiceProvider::class,
    ReclutamientoServiceProvider::class,
    TenantServiceProvider::class,
];
