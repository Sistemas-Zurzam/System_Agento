<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integraciones por API de sistemas externos (primer caso: Firme Studio, clases dictadas).
 *
 *  - integracion_credenciales: una credencial (token) por empresa y proveedor. Solo se guarda el hash
 *    SHA-256; el token en claro se muestra una única vez al crearlo.
 *  - clases_externas_lotes: un envío (p. ej. el mes) de un proveedor. (empresa, proveedor, referencia)
 *    es único: reenviar la misma referencia reemplaza el lote mientras no esté aprobado.
 *  - clases_externas: cada clase del lote, con la titular y quien la dictó (la suplencia se deduce).
 *
 * Aditiva y re-ejecutable: MySQL no revierte el DDL si una migración falla a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integracion_credenciales')) {
            Schema::create('integracion_credenciales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->string('proveedor', 40);
                $table->string('nombre', 100);
                $table->char('token_hash', 64);
                $table->char('token_prefijo', 8);
                $table->boolean('activo')->default(true);
                $table->timestamp('ultimo_uso_at')->nullable();
                $table->timestamp('expira_at')->nullable();
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('token_hash', 'integracion_cred_token_unique');
                $table->index(['empresa_id', 'proveedor'], 'integracion_cred_empresa_prov_idx');
            });
        }

        if (! Schema::hasTable('clases_externas_lotes')) {
            Schema::create('clases_externas_lotes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->foreignId('credencial_id')->nullable()->constrained('integracion_credenciales')->nullOnDelete();
                $table->string('proveedor', 40);
                $table->string('referencia', 60);
                $table->date('periodo_inicio');
                $table->date('periodo_fin');
                // recibido | observado | aprobado | anulado
                $table->string('estado', 20)->default('recibido');
                $table->string('enviado_por', 150)->nullable();
                $table->unsignedInteger('envios')->default(0);
                $table->unsignedInteger('total_clases')->default(0);
                $table->unsignedInteger('total_observadas')->default(0);
                $table->timestamp('recibido_at')->nullable();
                $table->timestamps();
                $table->unique(['empresa_id', 'proveedor', 'referencia'], 'clases_lotes_ref_unique');
            });
        }

        if (! Schema::hasTable('clases_externas')) {
            Schema::create('clases_externas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lote_id')->constrained('clases_externas_lotes')->cascadeOnDelete();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->string('clase_id', 60);
                $table->date('fecha');
                $table->time('hora_inicio');
                $table->time('hora_fin');
                $table->string('tipo_clase', 60)->nullable();
                $table->string('sede', 100)->nullable();
                // dictada | cancelada_estudio | no_dictada
                $table->string('estado', 30);
                $table->string('motivo_suplencia', 120)->nullable();
                $table->string('titular_dni', 20);
                $table->string('titular_nombre', 150);
                $table->foreignId('titular_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
                $table->string('ejecutor_dni', 20)->nullable();
                $table->string('ejecutor_nombre', 150)->nullable();
                $table->foreignId('ejecutor_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
                // null = válida; si no, el motivo por el que requiere revisión
                $table->string('observacion', 255)->nullable();
                $table->timestamps();
                $table->unique(['lote_id', 'clase_id'], 'clases_ext_lote_clase_unique');
                $table->index(['empresa_id', 'clase_id'], 'clases_ext_empresa_clase_idx');
                $table->index(['empresa_id', 'fecha'], 'clases_ext_empresa_fecha_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clases_externas');
        Schema::dropIfExists('clases_externas_lotes');
        Schema::dropIfExists('integracion_credenciales');
    }
};
