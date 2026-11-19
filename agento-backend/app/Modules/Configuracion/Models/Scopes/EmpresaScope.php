<?php

namespace App\Modules\Configuracion\Models\Scopes;

use App\Modules\Configuracion\Support\EmpresaContext;
use App\Modules\Configuracion\Support\EmpresaNoDefinidaException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Aislamiento por empresa. FALLA CERRADO:
 *
 *  1. Modo sistema declarado (EmpresaContext::comoSistema, con motivo): sin filtro.
 *  2. Empresa del contexto (middleware JWT, jobs, comandos): se filtra por ella.
 *  3. Usuario autenticado por el guard `api`: se filtra por su empresa activa
 *     (cubre respuestas en streaming que se ejecutan fuera del middleware).
 *  4. Nada de lo anterior: en local/testing lanza EmpresaNoDefinidaException; en
 *     producción la consulta no devuelve filas.
 */
class EmpresaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (EmpresaContext::esSistema()) {
            return;
        }

        $empresaId = EmpresaContext::actual() ?? auth('api')->user()?->empresa_id;

        if ($empresaId) {
            $builder->where($model->getTable().'.empresa_id', $empresaId);

            return;
        }

        if (app()->environment(['local', 'testing'])) {
            throw EmpresaNoDefinidaException::para($model->getTable());
        }

        $builder->whereRaw('1 = 0');
    }
}
