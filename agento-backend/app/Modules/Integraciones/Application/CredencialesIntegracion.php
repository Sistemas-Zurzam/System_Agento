<?php

namespace App\Modules\Integraciones\Application;

use App\Modules\Integraciones\Models\CredencialIntegracion;
use Illuminate\Support\Str;

/**
 * Alta y verificación de credenciales de integración. El token solo existe en claro al crearlo; en la
 * base se guarda su SHA-256 (un token aleatorio de 256 bits no necesita un hash lento).
 */
class CredencialesIntegracion
{
    /**
     * @return array{credencial: CredencialIntegracion, token: string} el token en claro se muestra una sola vez
     */
    public function crear(int $empresaId, string $proveedor, string $nombre, ?int $creadoPor = null): array
    {
        $token = 'agt_'.Str::random(48);

        $credencial = CredencialIntegracion::create([
            'empresa_id' => $empresaId,
            'proveedor' => $proveedor,
            'nombre' => $nombre,
            'token_hash' => $this->hash($token),
            'token_prefijo' => substr($token, 0, 8),
            'creado_por' => $creadoPor,
        ]);

        return ['credencial' => $credencial, 'token' => $token];
    }

    /** La credencial vigente de ese proveedor a la que pertenece el token, o null. Marca el último uso. */
    public function verificar(?string $token, string $proveedor): ?CredencialIntegracion
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $credencial = CredencialIntegracion::where('token_hash', $this->hash($token))
            ->where('proveedor', $proveedor)
            ->first();

        if (! $credencial || ! $credencial->estaVigente()) {
            return null;
        }

        $credencial->forceFill(['ultimo_uso_at' => now()])->save();

        return $credencial;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
