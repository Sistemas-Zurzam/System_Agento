<?php

namespace App\Modules\PortalAgento\Support;

use Illuminate\Support\Facades\DB;

/**
 * Datos iniciales de los catálogos de Agento (solo INSERT en tablas nuevas, idempotente).
 *
 * Las áreas, niveles y jornadas son los valores FIJOS del contrato (openapi.yaml: Area, Level, Schedule).
 * El ubigeo es un catálogo INICIAL (capitales departamentales y los distritos principales de Lima y
 * Callao); se amplía con el catálogo completo del INEI con otro seeder de solo inserción
 * (docs/agento/brechas-contrato.md).
 */
final class CatalogosIniciales
{
    /** @var list<string> */
    public const AREAS = [
        'Ventas y comercial', 'Administración', 'Atención al cliente', 'Logística y almacén',
        'Producción y operaciones', 'Tecnología', 'Salud', 'Educación', 'Construcción y minería',
        'Gastronomía y turismo', 'Finanzas y contabilidad',
    ];

    /** @var array<string, string> */
    public const NIVELES = [
        'operativo' => 'Operativo', 'asistente' => 'Asistente / auxiliar', 'tecnico' => 'Técnico',
        'analista' => 'Analista / especialista', 'supervisor' => 'Supervisor', 'jefatura' => 'Jefatura / gerencia',
    ];

    /** @var array<string, string> */
    public const JORNADAS = [
        'completa' => 'Tiempo completo', 'medio_tiempo' => 'Medio tiempo', 'por_horas' => 'Por horas',
        'nocturna' => 'Nocturna', 'fines_de_semana' => 'Fines de semana', 'regimen_minero' => 'Régimen minero',
        'por_campana' => 'Por campaña',
    ];

    /** @var list<string> */
    public const BENEFICIOS = [
        'Seguro de salud', 'Todos los beneficios de ley', 'Bonos por desempeño', 'Capacitación',
        'Movilidad', 'Alimentación', 'Línea de carrera', 'Horario flexible', 'Uniforme',
    ];

    /**
     * [codigo INEI, departamento, provincia, distrito]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const UBIGEO = [
        ['010101', 'Amazonas', 'Chachapoyas', 'Chachapoyas'],
        ['020101', 'Áncash', 'Huaraz', 'Huaraz'],
        ['030101', 'Apurímac', 'Abancay', 'Abancay'],
        ['040101', 'Arequipa', 'Arequipa', 'Arequipa'],
        ['050101', 'Ayacucho', 'Huamanga', 'Ayacucho'],
        ['060101', 'Cajamarca', 'Cajamarca', 'Cajamarca'],
        ['070101', 'Callao', 'Callao', 'Callao'],
        ['080101', 'Cusco', 'Cusco', 'Cusco'],
        ['090101', 'Huancavelica', 'Huancavelica', 'Huancavelica'],
        ['100101', 'Huánuco', 'Huánuco', 'Huánuco'],
        ['110101', 'Ica', 'Ica', 'Ica'],
        ['120101', 'Junín', 'Huancayo', 'Huancayo'],
        ['130101', 'La Libertad', 'Trujillo', 'Trujillo'],
        ['140101', 'Lambayeque', 'Chiclayo', 'Chiclayo'],
        ['150101', 'Lima', 'Lima', 'Lima'],
        ['150103', 'Lima', 'Lima', 'Ate'],
        ['150104', 'Lima', 'Lima', 'Barranco'],
        ['150110', 'Lima', 'Lima', 'Comas'],
        ['150113', 'Lima', 'Lima', 'Jesús María'],
        ['150114', 'Lima', 'Lima', 'La Molina'],
        ['150116', 'Lima', 'Lima', 'Lince'],
        ['150117', 'Lima', 'Lima', 'Los Olivos'],
        ['150120', 'Lima', 'Lima', 'Magdalena del Mar'],
        ['150122', 'Lima', 'Lima', 'Miraflores'],
        ['150121', 'Lima', 'Lima', 'Pueblo Libre'],
        ['150130', 'Lima', 'Lima', 'San Borja'],
        ['150131', 'Lima', 'Lima', 'San Isidro'],
        ['150132', 'Lima', 'Lima', 'San Juan de Lurigancho'],
        ['150136', 'Lima', 'Lima', 'San Miguel'],
        ['150140', 'Lima', 'Lima', 'Santiago de Surco'],
        ['150141', 'Lima', 'Lima', 'Surquillo'],
        ['150142', 'Lima', 'Lima', 'Villa El Salvador'],
        ['160101', 'Loreto', 'Maynas', 'Iquitos'],
        ['170101', 'Madre de Dios', 'Tambopata', 'Tambopata'],
        ['180101', 'Moquegua', 'Mariscal Nieto', 'Moquegua'],
        ['190101', 'Pasco', 'Pasco', 'Chaupimarca'],
        ['200101', 'Piura', 'Piura', 'Piura'],
        ['210101', 'Puno', 'Puno', 'Puno'],
        ['220101', 'San Martín', 'Moyobamba', 'Moyobamba'],
        ['230101', 'Tacna', 'Tacna', 'Tacna'],
        ['240101', 'Tumbes', 'Tumbes', 'Tumbes'],
        ['250101', 'Ucayali', 'Coronel Portillo', 'Callería'],
    ];

    public static function sembrar(): void
    {
        $ahora = now();

        foreach (self::AREAS as $orden => $etiqueta) {
            self::insertarSiNoExiste('agento_areas', 'etiqueta', $etiqueta, ['orden' => $orden + 1]);
        }
        $orden = 0;
        foreach (self::NIVELES as $clave => $etiqueta) {
            self::insertarSiNoExiste('agento_niveles', 'clave', $clave, ['etiqueta' => $etiqueta, 'orden' => ++$orden]);
        }
        $orden = 0;
        foreach (self::JORNADAS as $clave => $etiqueta) {
            self::insertarSiNoExiste('agento_jornadas', 'clave', $clave, ['etiqueta' => $etiqueta, 'orden' => ++$orden]);
        }
        foreach (self::BENEFICIOS as $i => $etiqueta) {
            self::insertarSiNoExiste('agento_beneficios', 'etiqueta', $etiqueta, ['orden' => $i + 1]);
        }
        foreach (self::UBIGEO as [$codigo, $departamento, $provincia, $distrito]) {
            self::insertarSiNoExiste('agento_ubigeo', 'codigo', $codigo, [
                'departamento' => $departamento,
                'provincia' => $provincia,
                'distrito' => $distrito,
                'etiqueta' => $distrito === $departamento ? $departamento : "{$distrito}, {$departamento}",
                'etiqueta_normalizada' => Texto::normalizar("{$distrito} {$provincia} {$departamento}"),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $resto
     */
    private static function insertarSiNoExiste(string $tabla, string $columna, string $valor, array $resto): void
    {
        if (DB::table($tabla)->where($columna, $valor)->exists()) {
            return;
        }

        DB::table($tabla)->insert([$columna => $valor, ...$resto]);
    }
}
