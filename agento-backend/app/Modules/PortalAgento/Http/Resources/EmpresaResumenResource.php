<?php

namespace App\Modules\PortalAgento\Http\Resources;

use App\Modules\PortalAgento\Models\Publicacion;

/**
 * CompanySummary del contrato para una oferta. Una empresa confidencial NUNCA se identifica: ni nombre,
 * ni slug, ni id real. El contrato exige rating, reviewsCount y respondsFast: en modo agencia no existen
 * reseñas, por eso van en 0 / false (Agento los oculta; docs/agento/brechas-contrato.md).
 */
final class EmpresaResumenResource
{
    /**
     * @return array<string, mixed>
     */
    public static function datos(Publicacion $publicacion): array
    {
        if ($publicacion->proceso->empresa_confidencial) {
            return [
                'id' => 'confidencial', 'slug' => 'confidencial', 'name' => 'Empresa confidencial',
                'verified' => false, 'rating' => 0, 'reviewsCount' => 0, 'respondsFast' => false, 'sector' => '',
            ];
        }

        $perfil = $publicacion->empresaPublica;

        return [
            'id' => (string) $perfil?->slug,
            'slug' => (string) $perfil?->slug,
            'name' => (string) $publicacion->empresa?->nombre_comercial,
            'verified' => false,
            'rating' => 0,
            'reviewsCount' => 0,
            'respondsFast' => false,
            'sector' => (string) ($perfil?->sector ?? ''),
        ];
    }
}
