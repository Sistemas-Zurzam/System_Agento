<?php

namespace App\Modules\PortalAgento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalAgento\Application\ConsultaOfertas;
use App\Modules\PortalAgento\Models\AreaAgento;
use App\Modules\PortalAgento\Models\BeneficioAgento;
use App\Modules\PortalAgento\Models\JornadaAgento;
use App\Modules\PortalAgento\Models\NivelAgento;
use App\Modules\PortalAgento\Models\Ubigeo;
use App\Modules\PortalAgento\Support\Texto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catálogos públicos. /catalog/cargos y /catalog/places son del contrato; /catalog/{areas,niveles,jornadas,
 * beneficios,ubigeo} son una EXTENSIÓN (el contrato solo tiene los catálogos de administración).
 */
class CatalogosController extends Controller
{
    public function __construct(private readonly ConsultaOfertas $ofertas) {}

    public function cargos(Request $request): JsonResponse
    {
        $q = Texto::normalizar($this->consulta($request));

        $titulos = $this->ofertas->visibles()->with('proceso:id,cargo')->get(['id', 'proceso_id', 'titulo_publico'])
            ->map(fn ($p) => $p->titulo_publico ?: $p->proceso?->cargo)
            ->filter()->unique()
            ->filter(fn (string $t) => str_contains(Texto::normalizar($t), $q))
            ->take(8)->values();

        return response()->json($titulos->map(fn (string $label) => ['label' => $label])->all());
    }

    public function lugares(Request $request): JsonResponse
    {
        $q = Texto::normalizar($this->consulta($request));

        $lugares = Ubigeo::query()->where('etiqueta_normalizada', 'like', "%{$q}%")->orderBy('etiqueta')->limit(8)->get();

        return response()->json($lugares->map(fn (Ubigeo $u) => array_filter([
            'label' => $u->etiqueta,
            'hint' => $u->distrito === $u->departamento ? null : "Provincia de {$u->provincia}",
        ]))->all());
    }

    public function listado(string $tipo): JsonResponse
    {
        $items = match ($tipo) {
            'areas' => AreaAgento::query()->orderBy('orden')->pluck('etiqueta')->all(),
            'beneficios' => BeneficioAgento::query()->orderBy('orden')->pluck('etiqueta')->all(),
            'niveles' => NivelAgento::query()->orderBy('orden')->get()->map(fn ($n) => ['value' => $n->clave, 'label' => $n->etiqueta])->all(),
            'jornadas' => JornadaAgento::query()->orderBy('orden')->get()->map(fn ($j) => ['value' => $j->clave, 'label' => $j->etiqueta])->all(),
            'ubigeo' => Ubigeo::query()->orderBy('etiqueta')->get()->map(fn (Ubigeo $u) => [
                'codigo' => $u->codigo, 'label' => $u->etiqueta, 'departamento' => $u->departamento, 'provincia' => $u->provincia, 'distrito' => $u->distrito,
            ])->all(),
            default => throw new NotFoundHttpException,
        };

        return response()->json(['kind' => $tipo, 'items' => $items]);
    }

    private function consulta(Request $request): string
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            // El autocompletado exige al menos 2 letras (422 invalid_request).
            $request->validate(['q' => ['required', 'string', 'min:2']], ['q.required' => 'Escribe al menos 2 letras.', 'q.min' => 'Escribe al menos 2 letras.']);
        }

        return mb_substr($q, 0, 80);
    }
}
