<?php

namespace App\Modules\Integraciones\Console;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Integraciones\Application\CredencialesIntegracion;
use App\Modules\Integraciones\Models\CredencialIntegracion;
use Illuminate\Console\Command;

/**
 * Crea la credencial (token) de una empresa para un proveedor. El token se muestra UNA sola vez: en la base
 * solo queda su hash. (La pantalla de administración de integraciones llegará en una fase posterior.)
 */
class CrearCredencialIntegracionCommand extends Command
{
    protected $signature = 'integraciones:crear-credencial {empresa : ID de la empresa} {proveedor=firme_studio : Proveedor} {--nombre= : Nombre descriptivo}';

    protected $description = 'Crea la credencial de una integración y muestra el token una sola vez';

    public function handle(CredencialesIntegracion $credenciales): int
    {
        $proveedor = (string) $this->argument('proveedor');
        if ($proveedor !== CredencialIntegracion::FIRME_STUDIO) {
            $this->error('Proveedor no soportado. Disponible: '.CredencialIntegracion::FIRME_STUDIO);

            return self::FAILURE;
        }

        $empresa = Empresa::find((int) $this->argument('empresa'));
        if (! $empresa) {
            $this->error('La empresa indicada no existe.');

            return self::FAILURE;
        }

        ['credencial' => $credencial, 'token' => $token] = $credenciales->crear(
            $empresa->id,
            $proveedor,
            (string) ($this->option('nombre') ?: $proveedor),
        );

        $this->info("Credencial #{$credencial->id} creada para la empresa #{$empresa->id} ({$proveedor}).");
        $this->line('Token (cópialo ahora; no se volverá a mostrar):');
        $this->line($token);

        return self::SUCCESS;
    }
}
