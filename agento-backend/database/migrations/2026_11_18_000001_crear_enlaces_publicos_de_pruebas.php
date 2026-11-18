<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlace compartible de una prueba: quien lo abre inicia sesión con Google y
 * rinde la prueba; queda registrado su correo. Cada participante usa una
 * asignación propia (sin postulación), identificada por el enlace + correo.
 *
 * Re-ejecutable: MySQL no revierte el DDL si una migración falla a medias,
 * así que cada paso comprueba si ya se aplicó. El índice único lleva nombre
 * explícito: el automático supera los 64 caracteres que admite MySQL.
 */
return new class extends Migration
{
    private const INDICE_PARTICIPANTE = 'sel_prueba_asig_enlace_email_unique';

    public function up(): void
    {
        if (! Schema::hasTable('seleccion_prueba_enlaces')) {
            Schema::create('seleccion_prueba_enlaces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
                $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->boolean('activo')->default(true);
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['empresa_id', 'prueba_id']);
            });
        }

        // Re-declarar la columna como nullable es idempotente.
        Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
            $table->foreignId('postulacion_id')->nullable()->change();
        });

        if (! Schema::hasColumn('seleccion_prueba_asignaciones', 'enlace_id')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->foreignId('enlace_id')->nullable()->after('prueba_id')->constrained('seleccion_prueba_enlaces')->cascadeOnDelete();
            });
        }
        foreach (['participante_email' => 255, 'participante_nombre' => 255, 'participante_google_sub' => 64] as $columna => $largo) {
            if (! Schema::hasColumn('seleccion_prueba_asignaciones', $columna)) {
                Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) use ($columna, $largo) {
                    $table->string($columna, $largo)->nullable();
                });
            }
        }

        if (! Schema::hasIndex('seleccion_prueba_asignaciones', self::INDICE_PARTICIPANTE)) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->unique(['enlace_id', 'participante_email'], self::INDICE_PARTICIPANTE);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('seleccion_prueba_asignaciones', self::INDICE_PARTICIPANTE)) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->dropUnique(self::INDICE_PARTICIPANTE);
            });
        }
        if (Schema::hasColumn('seleccion_prueba_asignaciones', 'enlace_id')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->dropConstrainedForeignId('enlace_id');
            });
        }
        $columnas = array_values(array_filter(
            ['participante_email', 'participante_nombre', 'participante_google_sub'],
            fn ($c) => Schema::hasColumn('seleccion_prueba_asignaciones', $c),
        ));
        if ($columnas) {
            Schema::table('seleccion_prueba_asignaciones', fn (Blueprint $table) => $table->dropColumn($columnas));
        }
        Schema::dropIfExists('seleccion_prueba_enlaces');
    }
};
