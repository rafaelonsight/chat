<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Filtrar por tenant a mao em cada consulta e garantia de que um dia alguem
// esquece, e vazamento entre tenants e a pior falha possivel aqui. O escopo
// global torna o isolamento o padrao, nao a disciplina.
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if ($id = TenantContext::get()) {
                $query->where($query->getModel()->getTable().'.tenant_id', $id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id) && $id = TenantContext::get()) {
                $model->tenant_id = $id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
