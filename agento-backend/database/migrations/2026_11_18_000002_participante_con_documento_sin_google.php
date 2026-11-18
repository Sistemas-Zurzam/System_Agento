<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace de la prueba ya no usa Google: el participante declara nombre,
 * correo y documento. Se agrega el documento y se retira la columna del
 * identificador de Google, que nunca llegó a usarse en producción.
 * Re-ejecutable (MySQL no revierte el DDL si falla a medias).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seleccion_prueba_asignaciones', 'participante_documento')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->string('participante_documento', 20)->nullable();
            });
        }
        if (Schema::hasColumn('seleccion_prueba_asignaciones', 'participante_google_sub')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->dropColumn('participante_google_sub');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seleccion_prueba_asignaciones', 'participante_documento')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->dropColumn('participante_documento');
            });
        }
        if (! Schema::hasColumn('seleccion_prueba_asignaciones', 'participante_google_sub')) {
            Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
                $table->string('participante_google_sub', 64)->nullable();
            });
        }
    }
};
