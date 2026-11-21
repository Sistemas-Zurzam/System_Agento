<?php

use App\Modules\Crm\Http\Controllers\CanalWhatsappController;
use App\Modules\Crm\Http\Controllers\ConversacionController;
use App\Modules\Crm\Http\Controllers\WebhookWhatsappController;
use App\Modules\Reclutamiento\Http\Middleware\ExigirAlgunPermiso;
use Illuminate\Support\Facades\Route;

/*
| Módulo CRM: conversaciones de WhatsApp Cloud API (Meta). Incluido desde routes/api.php.
|
| /api/publico/crm/whatsapp/{token}: webhook PÚBLICO de Meta, sin JWT y con throttle. El canal (y la
|   empresa) salen del token secreto de la URL; cada aviso se valida con la firma X-Hub-Signature-256.
|
| /api/crm/...: panel (JWT). Permisos crm.conversaciones.ver / crm.conversaciones.responder /
|   crm.canales.gestionar (el rol administrador pasa siempre).
*/

Route::prefix('publico/crm/whatsapp/{token}')->middleware('throttle:crm-webhook')->group(function () {
    Route::get('/', [WebhookWhatsappController::class, 'verificar']);
    Route::post('/', [WebhookWhatsappController::class, 'recibir']);
});

Route::middleware('jwt')->prefix('crm')->group(function () {
    $ver = ExigirAlgunPermiso::class.':crm.conversaciones.ver,crm.conversaciones.responder';
    $canales = 'permiso:crm.canales.gestionar';

    Route::get('/canales/resumen', [CanalWhatsappController::class, 'resumen'])->middleware($ver);
    Route::get('/canales', [CanalWhatsappController::class, 'index'])->middleware($canales);
    Route::post('/canales', [CanalWhatsappController::class, 'store'])->middleware($canales);
    Route::put('/canales/{canal}', [CanalWhatsappController::class, 'update'])->whereNumber('canal')->middleware($canales);
    Route::delete('/canales/{canal}', [CanalWhatsappController::class, 'destroy'])->whereNumber('canal')->middleware($canales);
    Route::post('/canales/{canal}/regenerar-webhook', [CanalWhatsappController::class, 'regenerarWebhook'])->whereNumber('canal')->middleware($canales);

    Route::get('/conversaciones', [ConversacionController::class, 'index'])->middleware($ver);
    Route::get('/conversaciones/{conversacion}/mensajes', [ConversacionController::class, 'mensajes'])->whereNumber('conversacion')->middleware($ver);
    Route::post('/conversaciones/{conversacion}/leida', [ConversacionController::class, 'marcarLeida'])->whereNumber('conversacion')->middleware($ver);
    Route::post('/conversaciones/{conversacion}/mensajes', [ConversacionController::class, 'enviar'])->whereNumber('conversacion')
        ->middleware(['permiso:crm.conversaciones.responder', 'throttle:60,1']);
});
