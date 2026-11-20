<?php

namespace App\Modules\PortalAgento\Http\Requests;

use App\Modules\PortalAgento\Support\CatalogosIniciales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de GET /jobs (mismos nombres que la URL del navegador de Agento).
 */
class BuscarOfertasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'lugar' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'array', 'max:11'],
            'area.*' => ['string', Rule::in(CatalogosIniciales::AREAS)],
            'nivel' => ['nullable', 'array', 'max:6'],
            'nivel.*' => ['string', Rule::in(array_keys(CatalogosIniciales::NIVELES))],
            'jornada' => ['nullable', 'array', 'max:7'],
            'jornada.*' => ['string', Rule::in(array_keys(CatalogosIniciales::JORNADAS))],
            'modalidad' => ['nullable', 'array', 'max:3'],
            'modalidad.*' => ['string', Rule::in(['presencial', 'remoto', 'hibrido'])],
            'min' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'fecha' => ['nullable', Rule::in(['24h', '7d', '30d'])],
            'sinexp' => ['nullable', 'boolean'],
            'inclusiva' => ['nullable', 'boolean'],
            'verificadas' => ['nullable', 'boolean'],
            'consueldo' => ['nullable', 'boolean'],
            'orden' => ['nullable', Rule::in(['relevance', 'recent', 'salary'])],
            'pag' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'tam' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // area, nivel, jornada y modalidad son repetibles (?area=a&area=b o ?area[]=a): un valor suelto
        // se vuelve lista.
        // PHP solo conserva el último valor de una clave repetida sin corchetes: se leen todos de la query cruda.
        $repetibles = ['area' => [], 'nivel' => [], 'jornada' => [], 'modalidad' => []];
        foreach (explode('&', (string) $this->server->get('QUERY_STRING')) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');
            $clave = (string) preg_replace('/(\[\]|%5B%5D)$/i', '', urldecode($clave));
            if (isset($repetibles[$clave]) && $valor !== '') {
                $repetibles[$clave][] = urldecode($valor);
            }
        }
        $this->merge(array_filter($repetibles));
    }

    public function messages(): array
    {
        return [
            'area.*.in' => 'El área indicada no existe.',
            'nivel.*.in' => 'El nivel indicado no existe.',
            'jornada.*.in' => 'La jornada indicada no existe.',
            'modalidad.*.in' => 'La modalidad indicada no existe.',
            'tam.max' => 'El tamaño de página máximo es 50.',
            'orden.in' => 'El orden indicado no existe.',
            'fecha.in' => 'La antigüedad debe ser 24h, 7d o 30d.',
        ];
    }
}
