<?php

use App\Modules\PortalAgento\Support\CatalogosIniciales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publicación de ofertas en Agento (portal público), módulo PortalAgento.
 *
 * Solo tablas NUEVAS (nada se altera ni se actualiza en tablas existentes) y reversible. Re-ejecutable
 * (hasTable / hasIndex): MySQL no revierte el DDL si una migración falla a medias. Índices con nombre
 * explícito (límite de 64 caracteres de MySQL).
 *
 * - agento_areas / agento_niveles / agento_jornadas / agento_beneficios / agento_ubigeo: catálogos
 *   públicos (valores fijos del contrato openapi.yaml). Se siembran con INSERT idempotente.
 * - agento_empresas_publicas: perfil público mínimo de la empresa (slug estable, sector...), 1:1.
 * - agento_publicaciones: la publicación de un proceso de selección en Agento, 1:1 con seleccion_procesos.
 * - agento_publicacion_beneficio: beneficios de la oferta (pivote).
 * - agento_publicacion_eventos: historial de cada publicación (solo identificadores, sin datos personales).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agento_areas')) {
            Schema::create('agento_areas', function (Blueprint $table) {
                $table->id();
                $table->string('etiqueta', 60)->unique();
                $table->unsignedSmallInteger('orden')->default(0);
            });
        }

        foreach (['agento_niveles', 'agento_jornadas'] as $nombre) {
            if (! Schema::hasTable($nombre)) {
                Schema::create($nombre, function (Blueprint $table) {
                    $table->id();
                    $table->string('clave', 30)->unique();
                    $table->string('etiqueta', 60);
                    $table->unsignedSmallInteger('orden')->default(0);
                });
            }
        }

        if (! Schema::hasTable('agento_beneficios')) {
            Schema::create('agento_beneficios', function (Blueprint $table) {
                $table->id();
                $table->string('etiqueta', 80)->unique();
                $table->unsignedSmallInteger('orden')->default(0);
            });
        }

        if (! Schema::hasTable('agento_ubigeo')) {
            Schema::create('agento_ubigeo', function (Blueprint $table) {
                $table->id();
                $table->char('codigo', 6)->unique();
                $table->string('departamento', 60);
                $table->string('provincia', 60);
                $table->string('distrito', 80);
                $table->string('etiqueta', 160);
                $table->string('etiqueta_normalizada', 200);
                $table->index('departamento', 'agento_ubigeo_departamento_idx');
                $table->index('etiqueta_normalizada', 'agento_ubigeo_etiqueta_norm_idx');
            });
        }

        if (! Schema::hasTable('agento_empresas_publicas')) {
            Schema::create('agento_empresas_publicas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->unique()->constrained('empresas')->cascadeOnDelete();
                $table->string('slug', 120)->unique();
                $table->string('sector', 80)->nullable();
                $table->string('tamano', 10)->nullable();
                $table->text('descripcion')->nullable();
                $table->string('sitio_web', 200)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agento_publicaciones')) {
            Schema::create('agento_publicaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('proceso_id')->unique()->constrained('seleccion_procesos')->cascadeOnDelete();
                $table->string('slug', 160)->unique();
                $table->string('titulo_publico', 150)->nullable();
                $table->string('resumen', 300)->nullable();
                $table->foreignId('area_id')->nullable()->constrained('agento_areas')->nullOnDelete();
                $table->foreignId('nivel_id')->nullable()->constrained('agento_niveles')->nullOnDelete();
                $table->foreignId('jornada_id')->nullable()->constrained('agento_jornadas')->nullOnDelete();
                $table->char('ubigeo_codigo', 6)->nullable();
                $table->string('ubicacion_texto', 160)->nullable();
                $table->boolean('sin_experiencia')->default(false);
                $table->boolean('inclusiva')->default(false);
                $table->boolean('destacada')->default(false);
                $table->dateTime('destacada_hasta')->nullable();
                // borrador | programada | publicada | pausada | retirada | vencida
                $table->string('estado_publicacion', 20)->default('borrador');
                // 'agento' (pausa manual) o 'proceso' (el proceso está en pausa): decide quién puede reanudar.
                $table->string('pausada_por', 10)->nullable();
                $table->dateTime('programada_para')->nullable();
                $table->dateTime('publicada_at')->nullable();
                $table->dateTime('vigente_hasta')->nullable();
                $table->dateTime('retirada_at')->nullable();
                $table->string('motivo_retiro', 255)->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->text('texto_busqueda')->nullable();
                $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['estado_publicacion', 'publicada_at'], 'agento_pub_estado_publicada_idx');
                $table->index(['empresa_id', 'estado_publicacion'], 'agento_pub_empresa_estado_idx');
                $table->index('ubigeo_codigo', 'agento_pub_ubigeo_idx');
                $table->index(['estado_publicacion', 'programada_para'], 'agento_pub_programada_idx');
            });
        }

        // Búsqueda de texto completo solo en MySQL (los tests en SQLite usan LIKE).
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('agento_publicaciones')
            && ! Schema::hasIndex('agento_publicaciones', 'agento_pub_busqueda_ft')) {
            DB::statement('ALTER TABLE agento_publicaciones ADD FULLTEXT agento_pub_busqueda_ft (texto_busqueda)');
        }

        if (! Schema::hasTable('agento_publicacion_beneficio')) {
            Schema::create('agento_publicacion_beneficio', function (Blueprint $table) {
                $table->foreignId('publicacion_id')->constrained('agento_publicaciones')->cascadeOnDelete();
                $table->foreignId('beneficio_id')->constrained('agento_beneficios')->cascadeOnDelete();
                $table->primary(['publicacion_id', 'beneficio_id']);
            });
        }

        if (! Schema::hasTable('agento_publicacion_eventos')) {
            Schema::create('agento_publicacion_eventos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $table->foreignId('publicacion_id')->constrained('agento_publicaciones')->cascadeOnDelete();
                $table->string('evento', 40);
                $table->string('estado_desde', 20)->nullable();
                $table->string('estado_hasta', 20)->nullable();
                $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('detalle', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['publicacion_id', 'created_at'], 'agento_pub_eventos_pub_fecha_idx');
            });
        }

        CatalogosIniciales::sembrar();
    }

    public function down(): void
    {
        Schema::dropIfExists('agento_publicacion_eventos');
        Schema::dropIfExists('agento_publicacion_beneficio');
        Schema::dropIfExists('agento_publicaciones');
        Schema::dropIfExists('agento_empresas_publicas');
        Schema::dropIfExists('agento_ubigeo');
        Schema::dropIfExists('agento_beneficios');
        Schema::dropIfExists('agento_jornadas');
        Schema::dropIfExists('agento_niveles');
        Schema::dropIfExists('agento_areas');
    }
};
