<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\EnlacePruebaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enlace compartido de una prueba: pantalla previa (nombre y duración) y
 * registro del participante (nombre, correo y documento), que entrega el
 * token de su asignación personal para rendirla con /publico/pruebas/{token}.
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
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'documento' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
        ]);
        $acceso = $this->enlaces->acceder($token, $datos);

        return response()->json(['data' => [
            'token' => $acceso['token'],
            'email' => $acceso['asignacion']->participante_email,
            'nombre' => $acceso['asignacion']->participante_nombre,
        ]]);
    }
}
