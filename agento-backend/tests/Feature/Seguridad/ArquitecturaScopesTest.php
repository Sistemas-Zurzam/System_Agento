<?php

namespace Tests\Feature\Seguridad;

use Tests\TestCase;

/**
 * Reglas de arquitectura del aislamiento por empresa. Fallan si alguien:
 *  - añade un withoutGlobalScope(s) en un archivo que no está en la lista permitida (o suma uno
 *    más en uno que sí lo está);
 *  - usa el modo sistema sin motivo o desde un sitio no previsto.
 *
 * Cada entrada de la lista está justificada en docs/seguridad/auditoria-scopes.md. Para añadir
 * una: justificarla ahí (por qué es necesario y qué filtro explícito la acota) y subir el número.
 */
class ArquitecturaScopesTest extends TestCase
{
    /**
     * archivo (relativo a app/) => máximo de llamadas permitidas.
     *
     * @var array<string, int>
     */
    private const SIN_SCOPE_PERMITIDO = [
        'Modules/Configuracion/Http/Controllers/AreaController.php' => 1,
        'Modules/Configuracion/Http/Requests/StoreAreaRequest.php' => 1,
        'Modules/Configuracion/Http/Requests/StoreUsuarioRequest.php' => 1,
        'Modules/Configuracion/Http/Requests/UpdateUsuarioRequest.php' => 1,
        'Modules/Nominas/Http/Controllers/BoletaController.php' => 1,
        'Modules/Nominas/Http/Controllers/CicloRemunerativoController.php' => 4,
        'Modules/Nominas/Http/Controllers/LiquidacionCeseController.php' => 1,
        'Modules/Nominas/Services/BoletaService.php' => 3,
        'Modules/Nominas/Services/BonoAsistenciaService.php' => 3,
        'Modules/Nominas/Services/DescansoSemanalComplementariaService.php' => 4,
        'Modules/Nominas/Services/IncidenciasPendientesNominaService.php' => 1,
        'Modules/Nominas/Services/PlanillaComplementariaService.php' => 6,
        'Modules/Nominas/Services/ReporteBonoAsistenciaService.php' => 5,
        'Modules/Nominas/Support/ParametrosVigentesResolver.php' => 3,
        'Modules/Nominas/Support/ValorHoraOrdinaria.php' => 1,
        'Modules/Personas/Services/ColaboradorService.php' => 2,
        'Modules/PortalCliente/Application/AlcancePortal.php' => 3,
        'Modules/Reclutamiento/Application/AlcanceSeleccion.php' => 2,
        'Modules/Reclutamiento/Application/AplicacionPruebaService.php' => 5,
        'Modules/Reclutamiento/Application/EnlacePruebaService.php' => 5,
        'Modules/Reclutamiento/Application/EnvioAutomaticoPruebas.php' => 4,
        'Modules/Reclutamiento/Application/EtiquetadoAutomatico.php' => 1,
        'Modules/Reclutamiento/Application/PortalEmpleosService.php' => 1,
        'Modules/Reclutamiento/Application/PruebaService.php' => 6,
        'Modules/Reclutamiento/Application/RetencionCandidatosService.php' => 8,
        'Modules/Reclutamiento/Application/SolicitudService.php' => 3,
        'Modules/Reclutamiento/Jobs/EvaluarPostulacionJob.php' => 2,
        'Modules/Reclutamiento/Models/ProcesoSeleccion.php' => 1,
    ];

    /** Únicos sitios que pueden abrir el modo sistema directamente (el resto usa comoSistema con motivo). */
    private const PUEDEN_ENTRAR_EN_SISTEMA = [
        'Modules/Configuracion/Support/EmpresaContext.php',
        'Modules/Configuracion/Http/Middleware/ContextoSistema.php',
        'Modules/Reclutamiento/Http/Middleware/AutenticarCandidato.php',
        'Providers/TenantServiceProvider.php',
    ];

    /**
     * Código de app/ sin comentarios: [ruta relativa a app/, texto].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function codigo(): array
    {
        $base = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        $archivos = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }
            $lineas = array_filter(
                file($archivo->getPathname(), FILE_IGNORE_NEW_LINES),
                fn (string $linea) => ! preg_match('#^\s*(\*|//|/\*|\#)#', $linea),
            );
            $archivos[] = [str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1)), implode("\n", $lineas)];
        }

        return $archivos;
    }

    public function test_no_aparecen_withoutglobalscope_fuera_de_la_lista_permitida(): void
    {
        $infracciones = [];
        foreach ($this->codigo() as [$ruta, $texto]) {
            $usos = preg_match_all('/withoutGlobalScopes?\(/', $texto);
            $permitido = self::SIN_SCOPE_PERMITIDO[$ruta] ?? 0;
            if ($usos > $permitido) {
                $infracciones[] = "{$ruta}: {$usos} uso(s), permitido(s) {$permitido}";
            }
        }

        $this->assertSame([], $infracciones, "withoutGlobalScope(s) nuevo. Evítalo (EmpresaContext::como / filtro explícito) o justifícalo en docs/seguridad/auditoria-scopes.md y actualiza la lista:\n".implode("\n", $infracciones));
    }

    public function test_la_lista_permitida_no_tiene_entradas_obsoletas(): void
    {
        $reales = [];
        foreach ($this->codigo() as [$ruta, $texto]) {
            $reales[$ruta] = preg_match_all('/withoutGlobalScopes?\(/', $texto);
        }

        $obsoletas = [];
        foreach (self::SIN_SCOPE_PERMITIDO as $ruta => $maximo) {
            if (($reales[$ruta] ?? 0) < $maximo) {
                $obsoletas[] = "{$ruta}: permite {$maximo}, hay ".($reales[$ruta] ?? 0);
            }
        }

        $this->assertSame([], $obsoletas, "La lista permite más usos de los que existen; bájala para que la regla siga siendo estricta:\n".implode("\n", $obsoletas));
    }

    public function test_comosistema_siempre_lleva_motivo(): void
    {
        $infracciones = [];
        foreach ($this->codigo() as [$ruta, $texto]) {
            if (str_starts_with($ruta, 'Modules/Configuracion/Support/Empresa')) {
                continue;
            }
            // Cada llamada a comoSistema( debe nombrar motivo: con un texto dentro de la misma sentencia.
            foreach (array_slice(explode('comoSistema(', $texto), 1) as $trozo) {
                $sentencia = strstr($trozo, ';', true) ?: $trozo;
                if (! preg_match('/motivo:\s*[\'"][^\'"]{5,}/', $sentencia)) {
                    $infracciones[] = $ruta;
                }
            }
        }

        $this->assertSame([], $infracciones, 'EmpresaContext::comoSistema(...) exige motivo: \'texto\' (mínimo 5 caracteres).');
    }

    public function test_solo_los_puntos_previstos_entran_en_modo_sistema_directamente(): void
    {
        $infracciones = [];
        foreach ($this->codigo() as [$ruta, $texto]) {
            if (str_contains($texto, 'entrarSistema(') && ! in_array($ruta, self::PUEDEN_ENTRAR_EN_SISTEMA, true)) {
                $infracciones[] = $ruta;
            }
        }

        $this->assertSame([], $infracciones, 'Usa EmpresaContext::comoSistema(fn, motivo: ...) en vez de entrarSistema().');
    }
}
