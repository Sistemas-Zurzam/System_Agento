<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de escaneo antivirus de los documentos de candidatos.
 *
 * Aditiva y reversible; re-ejecutable. NO modifica ningún registro existente (ni UPDATE ni backfill):
 * la columna nace con DEFAULT 'legado', así que las filas que YA existían la reciben por el propio ALTER
 * (siguen descargables, sin escanear; `reclutamiento:escanear-documentos --legado` las escanea). Las filas
 * NUEVAS las inserta la aplicación con `pendiente_escaneo` explícito (AlmacenDocumentosCandidato y el
 * valor por defecto del modelo): no se pueden descargar hasta que un job las marque `limpio`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seleccion_documentos', 'estado_escaneo')) {
            Schema::table('seleccion_documentos', function (Blueprint $table) {
                $table->string('estado_escaneo', 20)->default('legado')->after('sha256');
                $table->string('resultado_escaneo', 190)->nullable()->after('estado_escaneo');
                $table->dateTime('escaneado_at')->nullable()->after('resultado_escaneo');
                $table->index('estado_escaneo', 'sel_doc_estado_escaneo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seleccion_documentos', 'estado_escaneo')) {
            Schema::table('seleccion_documentos', function (Blueprint $table) {
                $table->dropIndex('sel_doc_estado_escaneo');
                $table->dropColumn(['estado_escaneo', 'resultado_escaneo', 'escaneado_at']);
            });
        }
    }
};
