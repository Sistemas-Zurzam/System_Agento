<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Modules\Auditoria\Application\RegistradorAuditoria;
use App\Support\BloqueoLogin;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(LoginRequest $request, BloqueoLogin $bloqueo, RegistradorAuditoria $auditoria): JsonResponse
    {
        $login = (string) $request->validated('login');

        // Cuenta bloqueada: misma respuesta exista o no la cuenta.
        if (($espera = $bloqueo->segundosRestantes($login)) > 0) {
            return response()->json(['message' => 'Demasiados intentos. Espera un momento antes de volver a intentarlo.'], 429, ['Retry-After' => $espera]);
        }

        if (! $token = JWTAuth::attempt($request->credentials())) {
            if ($bloqueo->registrarFallo($login)) {
                $auditoria->cuentaBloqueada($request, $login);
            }

            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $bloqueo->limpiar($login);

        return $this->respondWithToken($token);
    }

    public function me(): UserResource
    {
        return new UserResource(JWTAuth::parseToken()->authenticate());
    }

    public function logout(): JsonResponse
    {
        JWTAuth::parseToken()->invalidate();

        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(JWTAuth::parseToken()->refresh());
    }

    private function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ]);
    }
}
