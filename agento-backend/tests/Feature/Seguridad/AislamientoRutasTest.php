<?php

namespace Tests\Feature\Seguridad;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteSignatureParameters;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\Concerns\InsertaFilasDeEmpresa;
use Tests\TestCase;

/**
 * Un administrador de la empresa A llama a CADA ruta autenticada que recibe un registro por
 * identificador (lectura, edición, descarga, acciones) usando un registro de la empresa B.
 * Ninguna debe responder con éxito: la respuesta debe ser 403/404.
 *
 * Cubre todos los módulos (Reclutamiento, Personas, Nóminas, Asistencia, Configuración...),
 * con o sin EmpresaScope en el modelo: el criterio es el comportamiento observable.
 */
class AislamientoRutasTest extends TestCase
{
    use CreaEscenarioSeleccion, InsertaFilasDeEmpresa, RefreshDatabase;

    protected bool $aislamientoEstricto = true;

    /**
     * Rutas con un hallazgo conocido y documentado (docs/seguridad/auditoria-scopes.md): se
     * ignoran SOLO mientras sigan sin corregir. Vacío = ninguna excepción.
     *
     * @var list<string>
     */
    private const EXCEPCIONES_CONOCIDAS = [];

    /**
     * Rutas que responden 422 en este recorrido: validan el cuerpo ANTES de comprobar la pertenencia
     * (la sonda va con cuerpo vacío), así que la prueba dinámica no es concluyente. Su autorización se
     * revisó leyendo el código (tieneAccesoA / empresaAutorizadaDelCiclo / autorizarAccion en servicios o
     * controladores; ver docs/seguridad/auditoria-scopes.md). Una ruta NUEVA que aparezca con 422 debe
     * revisarse y añadirse aquí a propósito.
     *
     * @var list<string>
     */
    private const VALIDAN_ANTES_DE_AUTORIZAR = [
        'PUT api/empresas/{empresa}',
        'PATCH api/empresas/{empresa}/estado',
        'POST api/empresas/{empresa}/logo',
        'POST api/empresas/{empresa}/sedes',
        'PUT api/empresas/{empresa}/sedes/{sede}',
        'POST api/empresas/{empresa}/areas',
        'POST api/empresas/{empresa}/reglas-tardanza',
        'POST api/empresas/{empresa}/cuentas-bancarias',
        'PUT api/empresas/{empresa}/cuentas-bancarias/{cuenta}',
        'PATCH api/empresas/{empresa}/cuentas-bancarias/{cuenta}/estado',
        'PUT api/usuarios/{usuario}',
        'PATCH api/usuarios/{usuario}/rol',
        'PATCH api/usuarios/{usuario}/estado',
        'PUT api/horarios/{horario}',
        'PUT api/ciclos-remunerativos/{ciclo}',
        'PATCH api/ciclos-remunerativos/{ciclo}/corte-asistencia',
        'PATCH api/ciclos-remunerativos/{ciclo}/incidencias-pendientes-cierre/{incidencia}',
        'POST api/ciclos-remunerativos/{ciclo}/comprobantes-rh/importar',
        'POST api/ciclos-remunerativos/{ciclo}/boletas/imprimir-masivo',
        'PATCH api/nominas/importaciones-historicas/{importacion}/detalles/{detalle}',
        'PATCH api/nominas/importaciones-historicas/{importacion}/detalles/{detalle}/confirmar-cts',
        'PATCH api/nominas/importaciones-historicas/{importacion}/detalles/{detalle}/confirmar-gratificacion',
        'PATCH api/nominas/importaciones-historicas/{importacion}/detalles/{detalle}/marcar-ignorado',
        'POST api/ciclos-remunerativos/{ciclo}/boletas/envio-correo/previsualizar',
        'POST api/ciclos-remunerativos/{ciclo}/boletas/envio-correo',
        'GET api/ciclos-remunerativos/{ciclo}/complementarias/descansos-semanales',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/descansos-semanales',
        'GET api/ciclos-remunerativos/{ciclo}/complementarias/descuentos',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/reintegrar-descuentos',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/comisiones',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/horas-extra',
        'GET api/ciclos-remunerativos/{ciclo}/complementarias/colaboradores-por-asistencia',
        'GET api/ciclos-remunerativos/{ciclo}/complementarias/bono-asistencia/excel',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/bono-asistencia/excel',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/bono-por-asistencia',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/regularizacion-feriado-historico',
        'POST api/planillas-complementarias/{complementaria}/colaboradores',
        'PATCH api/planillas-complementarias/{complementaria}/reabrir',
        'PATCH api/planillas-complementarias/{complementaria}/pagar',
        'POST api/planillas-complementarias/{complementaria}/telecredito-bcp/exportar',
        'POST api/planillas-complementarias/{complementaria}/bbva-netcash/exportar',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/telecredito-bcp/exportar-masivo',
        'POST api/ciclos-remunerativos/{ciclo}/complementarias/bbva-netcash/exportar-masivo',
        'POST api/ciclos-remunerativos/{ciclo}/bono-asistencia',
        'POST api/ciclos-remunerativos/{ciclo}/consumos-comida/importaciones',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    protected function tearDown(): void
    {
        $this->tearDownCreaEscenarioSeleccion();
        parent::tearDown();
    }

    /**
     * @return array<class-string<Model>, int> modelo => id de un registro de la empresa B
     */
    public function test_ninguna_ruta_entrega_recursos_de_otra_empresa(): void
    {
        $this->diferirClavesForaneas();
        $empresaA = $this->crearEmpresa('A');
        $empresaB = $this->crearEmpresa('B');
        // Rol con TODOS los permisos pero que no es "administrador" (ese es global por diseño, ver
        // test_el_administrador_global_opera_en_todas_las_empresas_por_diseno): así los permisos no
        // enmascaran el aislamiento y se ejercita la comprobación de pertenencia de cada ruta.
        $rolAmplio = Role::create(['clave' => 'prueba_amplia', 'nombre' => 'Prueba amplia']);
        $rolAmplio->permissions()->sync(Permission::query()->pluck('id'));
        $usuario = $this->usuarioCon($empresaA, 'prueba_amplia');
        $headers = $this->cabeceras($usuario);

        $registrosB = [Empresa::class => $empresaB->id];
        $probadas = 0;
        $fallos = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $ruta) {
            if (! str_starts_with($ruta->uri(), 'api/') || str_starts_with($ruta->uri(), 'api/publico/')) {
                continue;
            }

            $parametros = $this->parametrosDeModelo($ruta);
            if ($parametros === []) {
                continue;
            }

            $valores = [];
            foreach ($parametros as $nombre => $clase) {
                $registrosB[$clase] ??= $this->insertarFilaDeEmpresa((new $clase)->getTable(), $empresaB->id);
                $valores[$nombre] = $registrosB[$clase];
            }

            foreach (array_diff($ruta->methods(), ['HEAD', 'OPTIONS']) as $metodo) {
                $url = '/'.$this->construirUrl($ruta, $valores);
                $estado = $this->withHeaders($headers)->json($metodo, $url, [])->getStatusCode();
                $probadas++;

                $firma = "{$metodo} {$ruta->uri()}";
                if ($estado === 422 && in_array($firma, self::VALIDAN_ANTES_DE_AUTORIZAR, true)) {
                    continue;
                }
                if (! in_array($estado, [403, 404], true) && ! in_array($firma, self::EXCEPCIONES_CONOCIDAS, true)) {
                    $fallos[] = "{$firma} → {$estado}";
                }
            }
        }

        $this->assertGreaterThan(40, $probadas, 'El recorrido debe cubrir muchas rutas; si baja, la detección falló.');
        $this->assertSame([], $fallos, "Rutas que no aíslan a la empresa B de un usuario de A:\n".implode("\n", $fallos));
    }

    public function test_el_administrador_global_opera_en_todas_las_empresas_por_diseno(): void
    {
        // Decisión de diseño documentada (User::esAdministradorGlobal): un administrador de CUALQUIER
        // empresa opera en todas. Si esto cambia debe ser una decisión explícita, no un accidente.
        $empresaA = $this->crearEmpresa('A');
        $empresaB = $this->crearEmpresa('B');
        $admin = $this->usuarioCon($empresaA, 'administrador');

        $this->withHeaders($this->cabeceras($admin))->getJson("/api/empresas/{$empresaB->id}/sedes")->assertOk();
    }

    /**
     * Parámetros de ruta que reciben un modelo de una tabla con empresa_id (o la propia Empresa).
     *
     * @return array<string, class-string<Model>>
     */
    private function parametrosDeModelo(Route $ruta): array
    {
        $resultado = [];
        foreach (RouteSignatureParameters::fromAction($ruta->getAction(), ['subClass' => Model::class]) as $parametro) {
            $clase = $parametro->getType()?->getName();
            if (! $clase || ! is_subclass_of($clase, Model::class)) {
                continue;
            }
            $tabla = (new $clase)->getTable();
            if ($clase === Empresa::class || Schema::hasColumn($tabla, 'empresa_id')) {
                $resultado[$parametro->getName()] = $clase;
            }
        }

        return $resultado;
    }

    /**
     * @param  array<string, int>  $valores
     */
    private function construirUrl(Route $ruta, array $valores): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($valores[$m[1]] ?? 1), $ruta->uri());
    }
}
