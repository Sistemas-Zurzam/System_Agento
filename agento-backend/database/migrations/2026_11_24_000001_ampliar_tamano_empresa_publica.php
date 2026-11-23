<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `agento_empresas_publicas.tamano` nació como VARCHAR(10), pero el perfil de
 * empresa acepta hasta 40 caracteres (PerfilEmpresaRequest) y los valores
 * reales son del tipo "51-200 empleados". En MySQL el guardado fallaba con
 * "Data too long" (SQLite no lo hace cumplir, por eso no se veía en local).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agento_empresas_publicas')) {
            return;
        }

        Schema::table('agento_empresas_publicas', function (Blueprint $table) {
            $table->string('tamano', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No se reduce: truncaría los valores ya guardados.
    }
};
