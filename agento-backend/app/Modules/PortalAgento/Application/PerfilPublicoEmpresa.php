<?php

namespace App\Modules\PortalAgento\Application;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\PortalAgento\Models\EmpresaPublica;
use Illuminate\Support\Str;

/**
 * Perfil público de la empresa en Agento: se crea al preparar la primera publicación (slug estable).
 */
class PerfilPublicoEmpresa
{
    public function asegurar(Empresa $empresa): EmpresaPublica
    {
        return EmpresaContext::como((int) $empresa->id, function () use ($empresa) {
            $existente = EmpresaPublica::query()->where('empresa_id', $empresa->id)->first();

            return $existente ?? EmpresaPublica::create(['empresa_id' => $empresa->id, 'slug' => $this->slugLibre($empresa)]);
        });
    }

    /**
     * El slug es único entre TODAS las empresas: la comprobación necesita ver todas, por eso va en modo
     * sistema (con motivo registrado) y no con withoutGlobalScope.
     */
    private function slugLibre(Empresa $empresa): string
    {
        $base = Str::slug((string) $empresa->nombre_comercial) ?: 'empresa';

        return EmpresaContext::comoSistema(motivo: 'Agento: slug de empresa único entre todas las empresas', callback: function () use ($base) {
            $slug = $base;
            $n = 1;
            while (EmpresaPublica::query()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$n);
            }

            return $slug;
        });
    }
}
