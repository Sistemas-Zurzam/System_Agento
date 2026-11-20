<?php

use App\Modules\PortalAgento\Http\Controllers\CatalogosController;
use App\Modules\PortalAgento\Http\Controllers\EmpresasController;
use App\Modules\PortalAgento\Http\Controllers\OfertasController;
use App\Modules\PortalAgento\Http\Controllers\PublicacionAgentoController;
use App\Modules\PortalAgento\Http\Controllers\PublicacionDirectaController;
use App\Modules\PortalAgento\Http\Controllers\SeoController;
use App\Modules\PortalAgento\Http\Middleware\RespuestaApiV1;
use App\Modules\Reclutamiento\Http\Middleware\ExigirAlgunPermiso;
use Illuminate\Support\Facades\Route;

/*
| Publicación en Agento (módulo PortalAgento). Incluido desde routes/api.php.
|
| /api/v1: API PÚBLICA de lectura que consume el portal Agento (contrato: openapi.yaml de Agento).
|   Sin JWT, con rate limiting, errores { code, message, details }, ETag y Cache-Control. Modo sistema: la
|   empresa sale de cada publicación, nunca del request; la visibilidad se decide en un único lugar
|   (Domain\VisibilidadPublica).
|
| /api/seleccion/.../agento: pestaña «Publicación en Agento» del panel (JWT + servicio de reclutamiento).
|   Reutiliza los permisos seleccion.* existentes.
*/

Route::prefix('v1')
    ->middleware([RespuestaApiV1::class, 'throttle:agento-publico', 'contexto.sistema:API publica de Agento (la empresa sale de cada publicacion)'])
    ->group(function () {
        Route::middleware('throttle:agento-busqueda')->group(function () {
            Route::get('/jobs', [OfertasController::class, 'index']);
        });

        // «areas» antes que {slug}: no es una oferta.
        Route::get('/jobs/areas', [OfertasController::class, 'areas']);
        Route::get('/jobs/{slug}', [OfertasController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('/jobs/{slug}/similar', [OfertasController::class, 'similares'])->where('slug', '[a-z0-9\-]+');
        Route::get('/job-landings/{slug}', [SeoController::class, 'landing'])->where('slug', '[a-z0-9\-]+');

        Route::get('/companies', [EmpresasController::class, 'index']);
        Route::get('/companies/{slug}', [EmpresasController::class, 'show'])->where('slug', '[a-z0-9\-]+');
        Route::get('/companies/{slug}/jobs', [EmpresasController::class, 'ofertas'])->where('slug', '[a-z0-9\-]+');

        Route::get('/catalog/cargos', [CatalogosController::class, 'cargos']);
        Route::get('/catalog/places', [CatalogosController::class, 'lugares']);
        Route::get('/catalog/{tipo}', [CatalogosController::class, 'listado'])->whereIn('tipo', ['areas', 'niveles', 'jornadas', 'beneficios', 'ubigeo']);

        Route::get('/seo/sitemap', [SeoController::class, 'sitemap']);
        Route::get('/seo/jobs/{slug}', [SeoController::class, 'jsonLd'])->where('slug', '[a-z0-9\-]+');
    });

$alguno = fn (string ...$permisos) => ExigirAlgunPermiso::class.':'.implode(',', $permisos);

Route::middleware(['jwt', 'servicio:reclutamiento'])->prefix('seleccion')->group(function () use ($alguno) {
    $ver = $alguno('seleccion.seguimiento', 'seleccion.vacantes.gestionar', 'seleccion.candidatos.gestionar', 'seleccion.evaluaciones.gestionar');

    Route::get('/agento/catalogos', [PublicacionAgentoController::class, 'catalogos'])->middleware($ver);
    Route::get('/agento/estados', [PublicacionAgentoController::class, 'estados'])->middleware($ver);
    Route::get('/agento/ubigeo', [PublicacionAgentoController::class, 'ubigeo'])->middleware($ver);

    // Pantalla «Publicar en Agento»: perfil de la empresa activa y alta directa de un puesto (solicitud + proceso +
    // publicación en una transacción). Exige los tres permisos que hoy se reparten entre solicitar, decidir y publicar.
    Route::get('/agento/empresa', [PublicacionDirectaController::class, 'empresa'])->middleware($ver);
    Route::put('/agento/empresa', [PublicacionDirectaController::class, 'guardarEmpresa'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::get('/agento/puestos', [PublicacionDirectaController::class, 'puestos'])->middleware($ver);
    Route::post('/agento/puestos', [PublicacionDirectaController::class, 'store'])->middleware([
        'permiso:seleccion.solicitudes.crear', 'permiso:seleccion.solicitudes.decidir', 'permiso:seleccion.vacantes.gestionar',
    ]);

    Route::get('/procesos/{proceso}/agento', [PublicacionAgentoController::class, 'show'])->middleware($ver);
    Route::get('/procesos/{proceso}/agento/historial', [PublicacionAgentoController::class, 'historial'])->middleware($ver);
    Route::get('/procesos/{proceso}/agento/vista-previa', [PublicacionAgentoController::class, 'vistaPrevia'])->middleware($ver);
    Route::put('/procesos/{proceso}/agento', [PublicacionAgentoController::class, 'update'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::post('/procesos/{proceso}/agento/acciones/{accion}', [PublicacionAgentoController::class, 'accion'])->middleware('permiso:seleccion.vacantes.gestionar');
    Route::post('/procesos/{proceso}/agento/destacar', [PublicacionAgentoController::class, 'destacar'])->middleware('permiso:seleccion.vacantes.gestionar');
});
