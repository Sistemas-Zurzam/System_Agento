<?php

use App\Modules\Integraciones\Http\Controllers\ClasesExternasFirmeStudioController;
use App\Modules\Integraciones\Http\Controllers\LotesClasesExternasController;
use App\Modules\Integraciones\Http\Controllers\TarifasClaseController;
use App\Modules\Integraciones\Http\Middleware\AutenticarIntegracion;
use Illuminate\Support\Facades\Route;

/*
| Módulo Integraciones: sistemas externos que envían datos a Agento por API. Incluido desde routes/api.php.
|
| /api/integraciones/...: recepción máquina a máquina. Sin JWT: se autentican con el token de X-Api-Key (una
|   credencial por empresa y proveedor), que determina la empresa. Todas llevan throttle. Contrato en
|   docs/integraciones/firme-studio.md.
|
| /api/clases-externas/...: panel (JWT). Reutiliza los permisos nominas.* (no se crean permisos nuevos):
|   ver = nominas.ver, tarifas = nominas.gestionar_ciclos, calcular = nominas.calcular,
|   aprobar/anular = nominas.aprobar, pagar = nominas.pagar, TXT = nominas.bbva_netcash_exportar.
*/

Route::prefix('integraciones')->middleware('throttle:integraciones')->group(function () {
    Route::post('/firme-studio/clases', [ClasesExternasFirmeStudioController::class, 'store'])
        ->middleware(AutenticarIntegracion::class.':firme_studio');
});

Route::middleware('jwt')->prefix('clases-externas')->group(function () {
    Route::get('/tarifas', [TarifasClaseController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::post('/tarifas', [TarifasClaseController::class, 'store'])->middleware('permiso:nominas.gestionar_ciclos');

    Route::get('/lotes', [LotesClasesExternasController::class, 'index'])->middleware('permiso:nominas.ver');
    Route::get('/lotes/{lote}', [LotesClasesExternasController::class, 'show'])->middleware('permiso:nominas.ver');
    Route::post('/lotes/{lote}/calcular', [LotesClasesExternasController::class, 'calcular'])->middleware('permiso:nominas.calcular');
    Route::post('/lotes/{lote}/aprobar', [LotesClasesExternasController::class, 'aprobar'])->middleware('permiso:nominas.aprobar');
    Route::post('/lotes/{lote}/anular', [LotesClasesExternasController::class, 'anular'])->middleware('permiso:nominas.aprobar');
    Route::post('/lotes/{lote}/pagar', [LotesClasesExternasController::class, 'pagar'])->middleware('permiso:nominas.pagar');
    Route::post('/lotes/{lote}/bbva-netcash/exportar', [LotesClasesExternasController::class, 'exportarBbva'])->middleware('permiso:nominas.bbva_netcash_exportar');
});
