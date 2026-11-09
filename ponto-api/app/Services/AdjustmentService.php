<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicitações e tratamentos de ponto.
 * - Colaborador (PWA): cria como "pendente"; só vale depois de aprovado.
 * - RH/dono (painel): cria já aprovado.
 * A marcação original nunca é alterada (Portaria 671/2021).
 */
class AdjustmentService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{type: string, date?: string|null, time?: string|null, time_entry_id?: int|null, reason: string} $data */
    public function create(Employee $employee, User $by, array $data, bool $autoApprove): TimeAdjustment
    {
        $type = $data['type'];
        $entry = null;

        if (in_array($type, TimeAdjustment::ON_ENTRY, true)) {
            $entry = TimeEntry::forCompany($employee->company_id)
                ->where('employee_id', $employee->id)
                ->find($data['time_entry_id'] ?? null);

            if (! $entry) {
                throw ValidationException::withMessages(['time_entry_id' => 'Marcação não encontrada.']);
            }

            $taken = TimeAdjustment::forCompany($employee->company_id)
                ->where('time_entry_id', $entry->id)
                ->whereIn('status', ['pendente', 'aprovado'])
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['time_entry_id' => 'Já existe uma solicitação pendente ou aprovada para esta batida.']);
            }

            $date = $entry->marked_at->toDateString();
        } else {
            $date = CarbonImmutable::parse($data['date'])->toDateString();
        }

        if ($date < $employee->admission_date->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Data anterior à admissão.']);
        }
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Não é possível ajustar um dia futuro.']);
        }

        $proposed = null;
        if (in_array($type, TimeAdjustment::WITH_TIME, true)) {
            $proposed = CarbonImmutable::parse("{$date} {$data['time']}");
            if ($proposed->isFuture()) {
                throw ValidationException::withMessages(['time' => 'O horário informado ainda não aconteceu.']);
            }
            if ($entry && $proposed->format('H:i') === $entry->marked_at->format('H:i')) {
                throw ValidationException::withMessages(['time' => 'Informe um horário diferente do original.']);
            }
        }

        return DB::transaction(function () use ($employee, $by, $data, $type, $entry, $date, $proposed, $autoApprove) {
            $adjustment = TimeAdjustment::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'time_entry_id' => $entry?->id,
                'date' => $date,
                'type' => $type,
                'proposed_at' => $proposed,
                'reason' => $data['reason'],
                'status' => $autoApprove ? 'aprovado' : 'pendente',
                'requested_by' => $by->id,
                'approved_by' => $autoApprove ? $by->id : null,
                'decided_at' => $autoApprove ? now() : null,
            ]);

            $this->audit->log($autoApprove ? 'adjustment.created' : 'adjustment.requested', $adjustment, null, $adjustment->toArray());

            return $adjustment;
        });
    }

    public function decide(TimeAdjustment $adjustment, User $by, bool $approve, ?string $note = null): TimeAdjustment
    {
        if ($adjustment->status !== 'pendente') {
            throw ValidationException::withMessages(['status' => 'Esta solicitação já foi decidida.']);
        }
        if ($adjustment->requested_by === $by->id) {
            throw ValidationException::withMessages(['status' => 'Você não pode decidir a sua própria solicitação.']);
        }
        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Informe o motivo da rejeição.']);
        }

        // Outra solicitação sobre a mesma batida pode ter sido aprovada nesse meio-tempo.
        if ($approve && $adjustment->time_entry_id) {
            $taken = TimeAdjustment::where('time_entry_id', $adjustment->time_entry_id)
                ->where('status', 'aprovado')
                ->whereKeyNot($adjustment->id)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['status' => 'Já existe um ajuste aprovado para esta batida.']);
            }
        }

        $this->audit->update($adjustment, [
            'status' => $approve ? 'aprovado' : 'rejeitado',
            'approved_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ], $approve ? 'adjustment.approved' : 'adjustment.rejected');

        return $adjustment;
    }

    /** Gestor só decide sobre as equipes que gerencia; dono e RH, sobre todos. */
    public function canDecide(User $user, TimeAdjustment $adjustment): bool
    {
        if ($user->hasRole('owner', 'rh')) {
            return true;
        }

        return $user->hasRole('gestor')
            && $adjustment->employee()->whereHas('team', fn ($q) => $q->where('manager_user_id', $user->id))->exists();
    }
}
