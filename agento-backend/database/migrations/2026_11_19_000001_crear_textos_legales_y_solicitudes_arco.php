<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Textos legales versionados y solicitudes ARCO (Ley 29733).
 *
 * Aditiva y reversible; re-ejecutable (MySQL no revierte el DDL si falla a medias).
 *
 * - seleccion_textos_legales: tipo + versión + texto + hash + vigencia. El backend es la fuente
 *   de verdad del texto que se muestra y se acepta (antes vivía solo en el frontend).
 * - seleccion_solicitudes_arco: solicitudes de acceso, rectificación y cancelación del candidato,
 *   con estado y fecha límite, que atiende RR. HH.
 * - seleccion_consentimientos.texto_legal_id: enlaza cada aceptación con el texto exacto vigente.
 *
 * El texto sembrado es el que ya mostraba el frontend (TEXTO_CONSENTIMIENTO). NO es un texto legal
 * definitivo: debe validarlo un abogado (docs/legal/pendientes.md).
 */
return new class extends Migration
{
    private const TEXTO_CONSENTIMIENTO = 'Autorizo el tratamiento de mis datos personales y de mi CV para este proceso de selección y para que la empresa me considere en futuros procesos durante el plazo de conservación informado. Puedo solicitar el acceso, rectificación o eliminación de mis datos.';

    public function up(): void
    {
        if (! Schema::hasTable('seleccion_textos_legales')) {
            Schema::create('seleccion_textos_legales', function (Blueprint $table) {
                $table->id();
                $table->string('tipo', 40);
                $table->string('version', 30);
                $table->text('texto');
                $table->char('hash', 64);
                $table->dateTime('vigente_desde');
                $table->dateTime('vigente_hasta')->nullable();
                $table->timestamps();

                $table->unique(['tipo', 'version']);
                $table->index(['tipo', 'vigente_desde']);
            });
        }

        // Siembra del texto vigente (el mismo que ya se mostraba y cuyo hash ya se guardaba).
        $version = (string) config('reclutamiento.consentimiento.version', '2026-10-v1');
        if (! DB::table('seleccion_textos_legales')->where('tipo', 'consentimiento_postulacion')->where('version', $version)->exists()) {
            DB::table('seleccion_textos_legales')->insert([
                'tipo' => 'consentimiento_postulacion',
                'version' => $version,
                'texto' => self::TEXTO_CONSENTIMIENTO,
                'hash' => hash('sha256', self::TEXTO_CONSENTIMIENTO),
                'vigente_desde' => now(),
                'vigente_hasta' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('seleccion_solicitudes_arco')) {
            Schema::create('seleccion_solicitudes_arco', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
                $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
                $table->string('tipo', 20);
                $table->string('estado', 20)->default('pendiente');
                $table->json('detalle')->nullable();
                $table->text('respuesta')->nullable();
                $table->dateTime('solicitada_at');
                $table->dateTime('vence_at');
                $table->dateTime('atendida_at')->nullable();
                $table->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['empresa_id', 'estado', 'vence_at'], 'sel_arco_empresa_estado_vence');
                $table->index(['candidato_id', 'tipo', 'estado'], 'sel_arco_candidato_tipo_estado');
            });
        }

        if (! Schema::hasColumn('seleccion_consentimientos', 'texto_legal_id')) {
            Schema::table('seleccion_consentimientos', function (Blueprint $table) {
                $table->foreignId('texto_legal_id')->nullable()->constrained('seleccion_textos_legales')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seleccion_consentimientos', 'texto_legal_id')) {
            Schema::table('seleccion_consentimientos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('texto_legal_id');
            });
        }
        Schema::dropIfExists('seleccion_solicitudes_arco');
        Schema::dropIfExists('seleccion_textos_legales');
    }
};
