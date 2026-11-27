<?php

namespace App\Modules\Integraciones\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integraciones\Models\TarifaClase;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tarifa por clase de cada instructora, con historial. Una tarifa nueva es una fila nueva con su vigencia:
 * nunca se edita ni se borra, para no alterar periodos ya pagados.
 */
class TarifasClaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['colaborador_id' => ['nullable', 'integer']]);

        $tarifas = TarifaClase::with('colaborador:id,nombres,apellido_paterno,apellido_materno,numero_documento')
            ->whereIn('empresa_id', $request->user('api')->empresaIdsAutorizadas())
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $id) => $q->where('colaborador_id', $id))
            ->orderBy('colaborador_id')->orderByDesc('vigencia_desde')
            ->get();

        return response()->json(['data' => $tarifas->map(fn (TarifaClase $t) => [
            'id' => $t->id,
            'colaborador_id' => $t->colaborador_id,
            'dni' => $t->colaborador?->numero_documento,
            'instructora' => $t->colaborador ? trim("{$t->colaborador->apellido_paterno} {$t->colaborador->apellido_materno} {$t->colaborador->nombres}") : null,
            'monto' => (float) $t->monto,
            'vigencia_desde' => $t->vigencia_desde->toDateString(),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'vigencia_desde' => ['required', 'date_format:Y-m-d'],
        ]);

        // Colaborador no tiene EmpresaScope: la pertenencia se comprueba aquí (nunca se acepta empresa_id del request).
        $colaborador = Colaborador::with('empresa')->findOrFail($datos['colaborador_id']);
        abort_unless($request->user('api')->tieneAccesoA($colaborador->empresa), 403);

        if (! $colaborador->empresa->pago_por_clases_habilitado) {
            return response()->json([
                'message' => 'La empresa de esta instructora no tiene habilitado el pago por clases. Actívalo en Configuraciones → Empresas.',
            ], 422);
        }

        $repetida = TarifaClase::where('colaborador_id', $colaborador->id)->whereDate('vigencia_desde', $datos['vigencia_desde'])->exists();
        if ($repetida) {
            return response()->json([
                'message' => 'Ya existe una tarifa con esa vigencia. Para cambiarla, registra una nueva con otra fecha de vigencia.',
                'errors' => ['vigencia_desde' => ['Ya existe una tarifa con esa vigencia.']],
            ], 422);
        }

        $tarifa = TarifaClase::create([
            'empresa_id' => $colaborador->empresa_id,
            'colaborador_id' => $colaborador->id,
            'monto' => number_format((float) $datos['monto'], 2, '.', ''),
            'vigencia_desde' => $datos['vigencia_desde'],
            'creado_por' => $request->user('api')->id,
        ]);

        return response()->json(['message' => 'Tarifa registrada.', 'data' => ['id' => $tarifa->id]], 201);
    }
}
