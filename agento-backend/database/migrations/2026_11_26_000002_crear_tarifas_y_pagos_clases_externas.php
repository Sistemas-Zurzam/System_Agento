<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago por clase dictada (fases 2 a 4 de la integración con Firme Studio).
 *
 *  - tarifas_clase: tarifa por clase de cada instructora con vigencia. Nunca se sobrescribe: un cambio es
 *    una fila nueva con otra vigencia_desde (historial; los periodos ya pagados no se alteran).
 *  - clases_externas_pagos: una fila por instructora y lote con el bruto, la retención de 4ta y el neto, y
 *    la cuenta bancaria congelada al aprobar (de ahí sale el TXT del abono).
 *  - Columnas nuevas en las tablas de la fase 1 (propias de esta integración). Las filas existentes toman el
 *    DEFAULT de la columna; no hay UPDATE de datos.
 *
 * Aditiva y re-ejecutable: MySQL no revierte el DDL si una migración falla a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tarifas_clase')) {
            Schema::create('tarifas_clase', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
                $table->decimal('monto', 10, 2);
                $table->date('vigencia_desde');
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['colaborador_id', 'vigencia_desde'], 'tarifas_clase_colab_vigencia_unique');
                $table->index('empresa_id', 'tarifas_clase_empresa_idx');
            });
        }

        if (! Schema::hasColumn('clases_externas', 'tarifa_aplicada')) {
            Schema::table('clases_externas', function (Blueprint $table) {
                $table->decimal('tarifa_aplicada', 10, 2)->nullable();
                $table->decimal('monto', 10, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('clases_externas_lotes', 'total_bruto')) {
            Schema::table('clases_externas_lotes', function (Blueprint $table) {
                $table->decimal('total_bruto', 12, 2)->default(0);
                $table->decimal('total_retencion', 12, 2)->default(0);
                $table->decimal('total_neto', 12, 2)->default(0);
                $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('aprobado_at')->nullable();
                $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('pagado_at')->nullable();
                $table->string('referencia_pago', 120)->nullable();
                $table->string('observacion', 500)->nullable();
            });
        }

        if (! Schema::hasTable('clases_externas_pagos')) {
            Schema::create('clases_externas_pagos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lote_id')->constrained('clases_externas_lotes')->cascadeOnDelete();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
                $table->unsignedInteger('clases_pagadas')->default(0);
                $table->decimal('monto_bruto', 12, 2)->default(0);
                $table->decimal('retencion_4ta', 12, 2)->default(0);
                $table->decimal('monto_neto', 12, 2)->default(0);
                // recibido | observado | aprobado | pagado
                $table->string('estado', 20)->default('recibido');
                $table->string('observacion', 500)->nullable();
                $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
                $table->string('tipo_cuenta_snapshot', 20)->nullable();
                $table->string('moneda_snapshot', 3)->nullable();
                $table->string('numero_cuenta_snapshot', 30)->nullable();
                $table->string('cci_snapshot', 30)->nullable();
                $table->timestamps();
                $table->unique(['lote_id', 'colaborador_id'], 'clases_pagos_lote_colab_unique');
                $table->index('empresa_id', 'clases_pagos_empresa_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clases_externas_pagos');

        if (Schema::hasColumn('clases_externas_lotes', 'total_bruto')) {
            Schema::table('clases_externas_lotes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('aprobado_por');
                $table->dropConstrainedForeignId('pagado_por');
                $table->dropColumn(['total_bruto', 'total_retencion', 'total_neto', 'aprobado_at', 'pagado_at', 'referencia_pago', 'observacion']);
            });
        }
        if (Schema::hasColumn('clases_externas', 'tarifa_aplicada')) {
            Schema::table('clases_externas', fn (Blueprint $table) => $table->dropColumn(['tarifa_aplicada', 'monto']));
        }

        Schema::dropIfExists('tarifas_clase');
    }
};
