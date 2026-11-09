<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Feriado. company_id null = vale para todas as empresas (nacional/BA/Salvador). */
class Holiday extends Model
{
    protected $fillable = ['company_id', 'date', 'name', 'scope'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function scopeApplicableTo(Builder $query, int $companyId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }
}
