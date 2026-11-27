<?php

namespace App\Modules\Integraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integraciones\Application\LoteYaAprobadoException;
use App\Modules\Integraciones\Application\RecibirClasesExternas;
use App\Modules\Integraciones\Http\Requests\RecibirClasesRequest;
use App\Modules\Integraciones\Models\CredencialIntegracion;
use Illuminate\Http\JsonResponse;

/**
 * Recepción de las clases dictadas que envía Firme Studio. Máquina a máquina, sin JWT: el middleware
 * AutenticarIntegracion identifica la credencial y, con ella, la EMPRESA (nunca se toma del cuerpo).
 */
class ClasesExternasFirmeStudioController extends Controller
{
    public function __construct(private readonly RecibirClasesExternas $recibir) {}

    public function store(RecibirClasesRequest $request): JsonResponse
    {
        /** @var CredencialIntegracion $credencial */
        $credencial = $request->attributes->get('credencial_integracion');
        $datos = $request->validated();

        // El RUC es solo una verificación cruzada de que el token se configuró en el sistema correcto.
        if (isset($datos['ruc']) && $datos['ruc'] !== (string) $credencial->empresa()->value('ruc')) {
            return response()->json(['message' => 'El RUC no corresponde a la credencial.'], 422);
        }

        try {
            ['lote' => $lote, 'creado' => $creado] = $this->recibir->ejecutar($credencial, $datos);
        } catch (LoteYaAprobadoException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'lote_id' => $lote->id,
            'referencia' => $lote->referencia,
            'estado' => $lote->estado,
            'total_clases' => $lote->total_clases,
            'total_observadas' => $lote->total_observadas,
            'clases' => $lote->clases->map(fn ($c) => [
                'clase_id' => $c->clase_id,
                'estado' => $c->observacion === null ? 'ok' : 'observada',
                'suplencia' => $c->esSuplencia(),
                'observacion' => $c->observacion,
            ])->values(),
        ], $creado ? 201 : 200);
    }
}
