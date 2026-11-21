<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Models\TextoLegal;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Textos legales versionados: el backend es la fuente de verdad del texto que se muestra y se
 * acepta. La versión vigente es la última con vigente_desde pasado y sin vigente_hasta vencido.
 */
class TextosLegalesService
{
    public function vigente(string $tipo): ?TextoLegal
    {
        return TextoLegal::query()
            ->where('tipo', $tipo)
            ->where('vigente_desde', '<=', now())
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>', now()))
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    public function vigenteOFalla(string $tipo): TextoLegal
    {
        return $this->vigente($tipo) ?? throw new NotFoundHttpException;
    }

    /**
     * Verifica que lo que el candidato aceptó es EXACTAMENTE el texto vigente. La versión y el hash
     * son opcionales por compatibilidad con clientes que solo envían el texto: en ese caso se
     * compara el hash del texto recibido con el vigente.
     *
     * @throws ValidationException
     */
    public function verificarAceptacion(string $tipo, ?string $version, ?string $hash, string $textoRecibido): TextoLegal
    {
        $vigente = $this->vigente($tipo);
        if (! $vigente) {
            throw ValidationException::withMessages(['consentimiento' => 'No hay un texto de consentimiento vigente. Inténtalo más tarde.']);
        }

        $hashRecibido = $hash ?: hash('sha256', $textoRecibido);

        if (($version !== null && $version !== '' && $version !== $vigente->version) || ! hash_equals($vigente->hash, $hashRecibido)) {
            throw ValidationException::withMessages([
                'consentimiento_version' => 'El texto de consentimiento cambió. Recarga la página para ver y aceptar la versión vigente.',
            ]);
        }

        return $vigente;
    }
}
