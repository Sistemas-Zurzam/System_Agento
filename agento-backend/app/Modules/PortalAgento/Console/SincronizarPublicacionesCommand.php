<?php

namespace App\Modules\PortalAgento\Console;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\PortalAgento\Application\SincronizadorPublicaciones;
use Illuminate\Console\Command;

/**
 * Sale a publicar lo programado y vence lo que cumplió su vigencia o fecha de cierre. Recorre cada empresa
 * con su propio contexto (nunca cruza datos entre empresas).
 */
class SincronizarPublicacionesCommand extends Command
{
    protected $signature = 'agento:sincronizar-publicaciones {--empresa= : Limitar a una empresa (id)}';

    protected $description = 'Publica lo programado y vence las publicaciones de Agento cumplidas';

    public function handle(SincronizadorPublicaciones $sincronizador): int
    {
        $empresaIds = $this->option('empresa')
            ? [(int) $this->option('empresa')]
            : Empresa::query()->where('servicio_reclutamiento', true)->pluck('id')->all();

        $cambios = 0;
        foreach ($empresaIds as $empresaId) {
            $cambios += EmpresaContext::como($empresaId, fn () => $sincronizador->sincronizarEmpresa($empresaId));
        }

        $this->info("Publicaciones actualizadas: {$cambios}");

        return self::SUCCESS;
    }
}
