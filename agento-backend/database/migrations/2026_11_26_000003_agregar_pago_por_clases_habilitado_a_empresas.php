<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in por empresa para el pago por clases dictadas (instructoras que cobran por clase, p. ej. un estudio
 * de pilates): habilita la pestaña «Pago por clases» en Gestión de remuneraciones y la recepción de clases
 * por API. Cada empresa define su forma de pagar; con el valor por defecto (false) nada cambia para las
 * empresas existentes, que toman el DEFAULT de la columna sin ningún UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('empresas', 'pago_por_clases_habilitado')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->boolean('pago_por_clases_habilitado')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('empresas', 'pago_por_clases_habilitado')) {
            Schema::table('empresas', function (Blueprint $table) {
                $table->dropColumn('pago_por_clases_habilitado');
            });
        }
    }
};
