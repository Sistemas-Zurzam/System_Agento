<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos semanales de motorizados enviados por plataforma_zazu.
 *
 * Zazu calcula y cuadra la semana (días, entregas, bonos, ajustes) y la envía
 * aquí por API. Nóminas revisa por DNI, aprueba, genera el TXT BBVA de 4ta y
 * marca el pago; cada cambio de estado se avisa de vuelta a Zazu.
 *
 * Es un lote APARTE del ciclo remunerativo mensual: los ciclos son por empresa,
 * mensuales y no pueden solaparse. Los colaboradores con periodicidad_pago
 * "semanal" se excluyen del ciclo mensual para no pagarles dos veces (ver
 * BoletaService::colaboradoresElegibles()).
 *
 * Contrato: plataforma_zazu/backend/docs/INTEGRACION-AGENTO-PLANILLA.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_semanales_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('sistema_origen', 30)->default('zazu');
            $table->string('referencia', 60);
            $table->date('semana_inicio');
            $table->date('semana_fin');
            // recibido | observado | aprobado | pagado | anulado
            $table->string('estado', 20)->default('recibido');
            $table->decimal('total_bruto', 12, 2)->default(0);
            $table->decimal('total_retencion', 12, 2)->default(0);
            $table->decimal('total_neto', 12, 2)->default(0);
            $table->string('enviado_por', 150)->nullable();
            $table->unsignedInteger('envios')->default(1);
            $table->timestamp('recibido_at')->nullable();
            $table->string('observacion', 500)->nullable();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pagado_at')->nullable();
            $table->string('referencia_pago', 120)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sistema_origen', 'referencia'], 'pagos_semanales_lotes_referencia_unique');
            $table->index(['empresa_id', 'semana_inicio']);
        });

        Schema::create('pagos_semanales_lote_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('pagos_semanales_lotes')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('numero_documento', 20);
            $table->string('nombre_origen', 150);
            $table->string('alias_origen', 50)->nullable();
            $table->unsignedTinyInteger('dias_trabajados')->default(0);
            $table->unsignedTinyInteger('dias_base')->default(6);
            $table->unsignedInteger('pedidos_entregados')->default(0);
            $table->decimal('monto_dias', 10, 2)->default(0);
            $table->decimal('monto_bonos', 10, 2)->default(0);
            $table->decimal('adicional', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('monto_bruto', 10, 2);
            $table->decimal('retencion_4ta', 10, 2)->default(0);
            $table->decimal('monto_neto', 10, 2);
            // recibido | observado | aprobado | pagado
            $table->string('estado', 20)->default('recibido');
            $table->string('observacion', 500)->nullable();
            $table->string('aviso', 255)->nullable(); // no bloquea (ej. nombre distinto)
            $table->json('detalle_origen')->nullable();
            // Snapshot bancario congelado al aprobar (mismo criterio que boleta_datos_pago).
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
            $table->string('tipo_cuenta_snapshot', 30)->nullable();
            $table->string('moneda_snapshot', 10)->nullable();
            $table->string('numero_cuenta_snapshot', 40)->nullable();
            $table->string('cci_snapshot', 40)->nullable();
            $table->timestamps();

            $table->unique(['lote_id', 'numero_documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_semanales_lote_detalles');
        Schema::dropIfExists('pagos_semanales_lotes');
    }
};
