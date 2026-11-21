<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo CRM: canales de WhatsApp Cloud API (Meta), conversaciones y mensajes.
 *
 * Solo tablas NUEVAS y permisos nuevos (INSERT idempotente; nada existente se actualiza). Reversible y
 * re-ejecutable (hasTable): MySQL no revierte el DDL si una migración falla a medias. Índices con nombre
 * explícito (límite de 64 caracteres de MySQL).
 *
 * - crm_canales_whatsapp: un número de WhatsApp de la empresa. Token de acceso y App secret cifrados.
 *   webhook_token es el identificador secreto de la URL del webhook (Meta no envía la empresa).
 * - crm_conversaciones: una por número del cliente (wa_id) en cada canal.
 * - crm_mensajes: entrantes y salientes. wa_message_id evita duplicados cuando Meta reintenta.
 *   El texto va en `texto_mensaje`: la bitácora de auditoría oculta esa clave (dato personal).
 */
return new class extends Migration
{
    private const PERMISOS = [
        ['clave' => 'crm.conversaciones.ver', 'nombre' => 'Ver conversaciones de WhatsApp (CRM)', 'grupo' => 'CRM'],
        ['clave' => 'crm.conversaciones.responder', 'nombre' => 'Responder conversaciones de WhatsApp (CRM)', 'grupo' => 'CRM'],
        ['clave' => 'crm.canales.gestionar', 'nombre' => 'Configurar canales de WhatsApp (CRM)', 'grupo' => 'CRM'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('crm_canales_whatsapp')) {
            Schema::create('crm_canales_whatsapp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->string('nombre', 120);
                $table->string('phone_number_id', 40)->unique('crm_canales_phone_number_id_unique');
                $table->string('waba_id', 40)->nullable();
                $table->string('numero_visible', 30)->nullable();
                $table->text('access_token');
                $table->text('app_secret');
                $table->string('verify_token', 120);
                $table->char('webhook_token', 40)->unique('crm_canales_webhook_token_unique');
                $table->boolean('activo')->default(true);
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('empresa_id', 'crm_canales_empresa_idx');
            });
        }

        if (! Schema::hasTable('crm_conversaciones')) {
            Schema::create('crm_conversaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('canal_id')->constrained('crm_canales_whatsapp')->cascadeOnDelete();
                $table->string('wa_id', 32);
                $table->string('nombre_contacto', 150)->nullable();
                $table->string('ultimo_texto_mensaje', 255)->nullable();
                $table->timestamp('ultimo_mensaje_at')->nullable();
                $table->timestamp('ultimo_entrante_at')->nullable();
                $table->unsignedInteger('no_leidos')->default(0);
                $table->timestamps();
                $table->unique(['canal_id', 'wa_id'], 'crm_conversaciones_canal_wa_unique');
                $table->index(['empresa_id', 'ultimo_mensaje_at'], 'crm_conversaciones_empresa_ultimo_idx');
            });
        }

        if (! Schema::hasTable('crm_mensajes')) {
            Schema::create('crm_mensajes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('conversacion_id')->constrained('crm_conversaciones')->cascadeOnDelete();
                $table->string('direccion', 10);
                $table->string('tipo', 20)->default('text');
                $table->text('texto_mensaje')->nullable();
                $table->string('wa_message_id', 128)->nullable()->unique('crm_mensajes_wa_message_id_unique');
                $table->string('estado', 15)->default('recibido');
                $table->string('error', 255)->nullable();
                $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['conversacion_id', 'id'], 'crm_mensajes_conversacion_idx');
            });
        }

        $ahora = now();
        foreach (self::PERMISOS as $permiso) {
            DB::table('permissions')->insertOrIgnore([...$permiso, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('clave', array_column(self::PERMISOS, 'clave'))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        Schema::dropIfExists('crm_mensajes');
        Schema::dropIfExists('crm_conversaciones');
        Schema::dropIfExists('crm_canales_whatsapp');
    }
};
