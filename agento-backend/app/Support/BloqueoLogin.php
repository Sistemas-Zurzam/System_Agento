<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Bloqueo temporal de una cuenta tras N fallos de login consecutivos.
 *
 * La clave es el identificador escrito (login), exista o no la cuenta: así
 * el comportamiento es idéntico para cuentas reales e inexistentes y no
 * revela cuáles existen. Un login correcto reinicia el contador.
 */
class BloqueoLogin
{
    public function segundosRestantes(string $login): int
    {
        $hasta = (int) Cache::get($this->claveBloqueo($login), 0);

        return max(0, $hasta - now()->timestamp);
    }

    /**
     * Registra un fallo. Devuelve true si este fallo activó el bloqueo.
     */
    public function registrarFallo(string $login): bool
    {
        $minutos = max(1, (int) config('seguridad.login.bloqueo_minutos'));
        $clave = $this->claveFallos($login);

        Cache::add($clave, 0, now()->addMinutes($minutos));
        $fallos = (int) Cache::increment($clave);

        if ($fallos < (int) config('seguridad.login.max_fallos')) {
            return false;
        }

        Cache::put($this->claveBloqueo($login), now()->addMinutes($minutos)->timestamp, now()->addMinutes($minutos));
        Cache::forget($clave);

        return true;
    }

    public function limpiar(string $login): void
    {
        Cache::forget($this->claveFallos($login));
    }

    private function claveFallos(string $login): string
    {
        return 'login:fallos:'.$this->huella($login);
    }

    private function claveBloqueo(string $login): string
    {
        return 'login:bloqueo:'.$this->huella($login);
    }

    private function huella(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }
}
