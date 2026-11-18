<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\EnlacePruebaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enlace compartido de una prueba: pantalla previa (nombre y duración) y
 * acceso con Google, que entrega el token de la asignación personal para
 * rendirla con /publico/pruebas/{token}.
 */
class EnlacePruebaPublicoController extends Controller
{
    public function __construct(private readonly EnlacePruebaService $enlaces) {}

    public function show(string $token): JsonResponse
    {
        $prueba = $this->enlaces->informacion($token);

        return response()->json(['data' => [
            'nombre' => $prueba->nombre,
            'descripcion' => $prueba->descripcion,
            'tiempo_limite_minutos' => $prueba->tiempo_limite_minutos,
        ]]);
    }

    public function acceso(Request $request, string $token): JsonResponse
    {
        $datos = $request->validate(['credencial' => ['required', 'string', 'max:4096']]);
        $acceso = $this->enlaces->acceder($token, $datos['credencial']);

        return response()->json(['data' => [
            'token' => $acceso['token'],
            'email' => $acceso['asignacion']->participante_email,
            'nombre' => $acceso['asignacion']->participante_nombre,
        ]]);
    }
}
