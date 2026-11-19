<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de escaneo antivirus de los documentos de candidatos.
 *
 * Aditiva y reversible; re-ejecutable. Los archivos nuevos nacen `pendiente_escaneo` (no se pueden
 * descargar hasta que un job los marque `limpio`). Los que YA existían se marcan `legado`: siguen
 * descargables para no romper nada, y `reclutamiento:escanear-documentos --legado` los escanea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seleccion_documentos', 'estado_escaneo')) {
            Schema::table('seleccion_documentos', function (Blueprint $table) {
                $table->string('estado_escaneo', 20)->default('pendiente_escaneo')->after('sha256');
                $table->string('resultado_escaneo', 190)->nullable()->after('estado_escaneo');
                $table->dateTime('escaneado_at')->nullable()->after('resultado_escaneo');
                $table->index('estado_escaneo', 'sel_doc_estado_escaneo');
            });

            DB::table('seleccion_documentos')->update(['estado_escaneo' => 'legado']);
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
