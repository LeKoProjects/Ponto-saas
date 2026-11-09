<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Registra toda ação administrativa (RH, gestor, admin SaaS). */
class AuditLogger
{
    private const HIDDEN = ['password', 'remember_token', 'next_nsr', 'last_hash'];

    public function log(string $action, ?Model $entity = null, ?array $before = null, ?array $after = null, ?int $companyId = null): AuditLog
    {
        $user = auth()->user();

        return AuditLog::create([
            'company_id' => $companyId ?? $entity?->getAttribute('company_id') ?? $user?->company_id,
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'before' => $before ? $this->clean($before) : null,
            'after' => $after ? $this->clean($after) : null,
            'ip' => request()?->ip(),
        ]);
    }

    /** Atualiza o model e grava antes/depois apenas dos campos alterados. */
    public function update(Model $model, array $data, string $action): Model
    {
        $model->fill($data);
        $dirty = $model->getDirty();

        if ($dirty) {
            $before = array_intersect_key($model->getOriginal(), $dirty);
            $model->save();
            $this->log($action, $model, $before, $dirty);
        }

        return $model;
    }

    private function clean(array $data): array
    {
        return array_diff_key($data, array_flip(self::HIDDEN));
    }
}
