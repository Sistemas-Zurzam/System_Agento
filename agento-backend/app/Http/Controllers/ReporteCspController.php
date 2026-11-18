<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Recibe los reportes de violación de Content-Security-Policy que envían los
 * navegadores (formato clásico `csp-report` y Reporting API). Endpoint público
 * y sin estado: siempre responde 204, no confirma nada al emisor.
 *
 * Los reportes llevan URLs que pueden contener credenciales (los enlaces de
 * pruebas llevan el token en la ruta, y a veces hay parámetros): antes de
 * registrar se descartan consulta y fragmento y se enmascaran los tramos largos.
 */
class ReporteCspController extends Controller
{
    private const MAX_BYTES = 16384;

    private const CAMPOS = [
        'document-uri', 'documentURL', 'violated-directive', 'effective-directive', 'effectiveDirective',
        'blocked-uri', 'blockedURL', 'source-file', 'sourceFile', 'line-number', 'lineNumber', 'disposition',
    ];

    public function __invoke(Request $request): Response
    {
        $cuerpo = substr($request->getContent(), 0, self::MAX_BYTES);
        $datos = json_decode($cuerpo, true);

        foreach ($this->reportes($datos) as $reporte) {
            Log::channel('csp')->warning('Violación de CSP', $this->limpiar($reporte));
        }

        return response()->noContent();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportes(mixed $datos): array
    {
        if (! is_array($datos)) {
            return [];
        }
        if (isset($datos['csp-report']) && is_array($datos['csp-report'])) {
            return [$datos['csp-report']];
        }

        // Reporting API: lista de { type: 'csp-violation', body: {...} }.
        $reportes = [];
        foreach (array_slice($datos, 0, 10) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'csp-violation' && is_array($item['body'] ?? null)) {
                $reportes[] = $item['body'];
            }
        }

        return $reportes;
    }

    /**
     * @param  array<string, mixed>  $reporte
     * @return array<string, scalar|null>
     */
    private function limpiar(array $reporte): array
    {
        $limpio = [];
        foreach (self::CAMPOS as $campo) {
            if (! isset($reporte[$campo]) || ! is_scalar($reporte[$campo])) {
                continue;
            }
            $valor = (string) $reporte[$campo];
            $limpio[$campo] = str_contains($campo, 'uri') || str_contains(strtolower($campo), 'url') || str_contains(strtolower($campo), 'file')
                ? $this->limpiarUrl($valor)
                : mb_substr($valor, 0, 200);
        }

        return $limpio;
    }

    private function limpiarUrl(string $url): string
    {
        $url = preg_split('/[?#]/', $url, 2)[0];
        $url = preg_replace('#/[A-Za-z0-9_-]{20,}#', '/[token]', $url) ?? '';

        return mb_substr($url, 0, 300);
    }
}
