<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\App;

/**
 * Aislamiento multi-tenant por fila (Parte I §5) — nunca `where('organization_id', ...)`
 * manual esparcido por el código. Filtra por el tenant actual solo si algo lo
 * resolvió y lo dejó atado en el contenedor bajo la clave
 * 'domain.current_organization_id' — en el Hito 1 nada lo hace todavía
 * (eso es Hito 3/4, la capa de Application Commands / ChannelResolver), así
 * que hoy este scope es un no-op deliberado, no lógica de negocio.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (App::bound('domain.current_organization_id')) {
            $builder->where($model->qualifyColumn('organization_id'), App::make('domain.current_organization_id'));
        }
    }
}
