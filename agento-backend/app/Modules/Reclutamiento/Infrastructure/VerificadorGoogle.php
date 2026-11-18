<?php

namespace App\Modules\Reclutamiento\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Valida el ID token que entrega "Iniciar sesión con Google" (Google
 * Identity Services). Google verifica firma y vigencia en tokeninfo; aquí se
 * comprueba además que el token se emitió PARA esta aplicación (aud) y que el
 * correo está verificado. El correo/nombre jamás se toman del cliente.
 */
class VerificadorGoogle
{
    /**
     * @return array{email: string, nombre: ?string, sub: string}
     */
    public function verificar(string $credencial): array
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            throw new HttpException(503, 'El inicio de sesión con Google no está configurado.');
        }

        try {
            $respuesta = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $credencial]);
        } catch (Throwable) {
            throw new HttpException(503, 'No se pudo validar tu cuenta de Google. Inténtalo de nuevo.');
        }

        $datos = $respuesta->json();
        $valido = $respuesta->successful()
            && is_array($datos)
            && ($datos['aud'] ?? null) === $clientId
            && in_array($datos['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && filter_var($datos['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! empty($datos['email'])
            && ! empty($datos['sub']);

        if (! $valido) {
            throw ValidationException::withMessages(['credencial' => 'No se pudo verificar tu cuenta de Google. Vuelve a iniciar sesión.']);
        }

        return [
            'email' => mb_strtolower((string) $datos['email']),
            'nombre' => isset($datos['name']) ? (string) $datos['name'] : null,
            'sub' => (string) $datos['sub'],
        ];
    }
}
