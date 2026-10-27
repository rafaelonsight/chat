<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait AgendaTenantScoped
{
    protected static function bootAgendaTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $id=TenantContext::get();
            $id ? $query->where($query->getModel()->qualifyColumn('tenant_id'), $id) : $query->whereRaw('1 = 0');
        });
        static::saving(function ($model) {
            $tenant=TenantContext::get();
            if (!$tenant || ($model->exists && (int) $model->getOriginal('tenant_id') !== $tenant)
                || ($model->tenant_id && (int) $model->tenant_id !== $tenant)) {
                throw ValidationException::withMessages(['tenant_id'=>'Não foi possível identificar a conta autorizada para este cadastro.']);
            }
            $model->tenant_id=$tenant;
            $model->validateForAgenda();
        });
    }
}
