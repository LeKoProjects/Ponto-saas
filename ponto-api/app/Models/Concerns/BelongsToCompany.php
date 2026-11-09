<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant em banco único: filtra toda consulta pelo company_id do tenant
 * atual e preenche company_id automaticamente ao criar.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $tenant = app(Tenant::class);

            if ($tenant->check()) {
                $query->where($query->getModel()->getTable().'.company_id', $tenant->id());
            }
        });

        static::creating(function ($model) {
            $tenant = app(Tenant::class);

            if (empty($model->company_id) && $tenant->check()) {
                $model->company_id = $tenant->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->withoutGlobalScope('company')->where($this->getTable().'.company_id', $companyId);
    }
}
