<?php

use App\Modules\Auditoria\AuditoriaServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\PortalAgento\PortalAgentoServiceProvider;
use App\Modules\Reclutamiento\ReclutamientoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TenantServiceProvider;

return [
    AppServiceProvider::class,
    AuditoriaServiceProvider::class,
    CrmServiceProvider::class,
    PortalAgentoServiceProvider::class,
    ReclutamientoServiceProvider::class,
    TenantServiceProvider::class,
];
