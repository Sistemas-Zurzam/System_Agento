<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlace compartible de una prueba: quien lo abre inicia sesión con Google y
 * rinde la prueba; queda registrado su correo. Cada participante usa una
 * asignación propia (sin postulación), identificada por el enlace + correo.
 */
return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
            $table->foreignId('postulacion_id')->nullable()->change();
            $table->foreignId('enlace_id')->nullable()->after('prueba_id')->constrained('seleccion_prueba_enlaces')->cascadeOnDelete();
            $table->string('participante_email')->nullable()->after('enlace_id');
            $table->string('participante_nombre')->nullable()->after('participante_email');
            $table->string('participante_google_sub', 64)->nullable()->after('participante_nombre');

            $table->unique(['enlace_id', 'participante_email']);
        });
    }

    public function down(): void
    {
        Schema::table('seleccion_prueba_asignaciones', function (Blueprint $table) {
            $table->dropUnique(['enlace_id', 'participante_email']);
            $table->dropConstrainedForeignId('enlace_id');
            $table->dropColumn(['participante_email', 'participante_nombre', 'participante_google_sub']);
        });
        Schema::dropIfExists('seleccion_prueba_enlaces');
    }
};
