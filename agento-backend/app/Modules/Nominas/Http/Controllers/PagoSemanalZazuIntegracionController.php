<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Services\PagoSemanalYaAprobadoException;
use App\Modules\Nominas\Services\PagoSemanalZazuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entrada de máquina a máquina: plataforma_zazu envía la semana de pago de
 * sus motorizados. NO usa JWT: se autentica con la clave compartida en
 * `X-Api-Key` (services.zazu.webhook_secret, la misma que Agento ya usa para
 * avisarle a Zazu las marcaciones). Cerrado por defecto si no hay clave o no
 * hay empresa configurada.
 *
 * Contrato: plataforma_zazu/backend/docs/INTEGRACION-AGENTO-PLANILLA.md.
 */
class PagoSemanalZazuIntegracionController extends Controller
{
    public function __construct(private readonly PagoSemanalZazuService $service) {}

    public function store(Request $request): JsonResponse
    {
        $esperada = (string) config('services.zazu.webhook_secret');
        $recibida = trim((string) $request->header('X-Api-Key'));
        if ($esperada === '' || $recibida === '' || ! hash_equals($esperada, $recibida)) {
            return response()->json(['message' => 'Credencial inválida.'], 401);
        }

        $empresa = Empresa::find(config('services.zazu.empresa_id'));
        if (! $empresa) {
            Log::warning('pagos_semanales_zazu: falta ZAZU_EMPRESA_ID; el lote se rechaza.');

            return response()->json(['message' => 'Agento no tiene configurada la empresa de Zazu.'], 503);
        }

        $datos = $request->validate([
            'referencia' => ['required', 'string', 'max:60', 'regex:/^ZAZU-MOTOS-\d{4}-\d{2}-\d{2}$/'],
            'semana_inicio' => ['required', 'date'],
            'semana_fin' => ['required', 'date', 'after_or_equal:semana_inicio'],
            'enviado_por' => ['nullable', 'string', 'max:150'],
            'total' => ['nullable', 'numeric'],
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.dni' => ['required', 'string', 'max:20', 'distinct'],
            'lineas.*.nombre' => ['required', 'string', 'max:150'],
            'lineas.*.alias' => ['nullable', 'string', 'max:50'],
            'lineas.*.dias_trabajados' => ['nullable', 'integer', 'min:0', 'max:7'],
            'lineas.*.dias_base' => ['nullable', 'integer', 'min:1', 'max:7'],
            'lineas.*.sueldo_semanal' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.monto_dias' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.pedidos_entregados' => ['nullable', 'integer', 'min:0'],
            'lineas.*.monto_bonos' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.bonos' => ['nullable', 'array'],
            'lineas.*.adicional' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.monto_total' => ['required', 'numeric', 'min:0'],
            'lineas.*.observacion' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            ['lote' => $lote, 'creado' => $creado] = $this->service->recibir($empresa, $datos);
        } catch (PagoSemanalYaAprobadoException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'lote_id' => $lote->id,
            'estado' => $lote->estado,
            'lineas' => $lote->detalles->map(fn ($d) => [
                'dni' => $d->numero_documento,
                'estado' => $d->estado,
                'observacion' => $d->observacion,
            ])->values(),
        ], $creado ? 201 : 200);
    }
}
