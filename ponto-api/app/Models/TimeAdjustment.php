<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tratamento sobre a marcação original. A marcação nunca é alterada:
 * - inclusao:        acrescenta um horário esquecido (proposed_at);
 * - desconsideracao: ignora uma marcação (time_entry_id) no cálculo;
 * - alteracao:       ignora a marcação original e considera proposed_at no lugar.
 */
class TimeAdjustment extends Model
{
    use BelongsToCompany;

    public const TYPES = ['inclusao' => 'Inclusão', 'desconsideracao' => 'Exclusão', 'alteracao' => 'Alteração'];

    public const STATUSES = ['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'rejeitado' => 'Rejeitado'];

    /** Tipos que referenciam uma marcação existente. */
    public const ON_ENTRY = ['desconsideracao', 'alteracao'];

    /** Tipos que propõem um novo horário. */
    public const WITH_TIME = ['inclusao', 'alteracao'];

    protected $fillable = ['company_id', 'employee_id', 'time_entry_id', 'date', 'type', 'proposed_at', 'reason', 'attachment_path', 'status', 'requested_by', 'approved_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['date' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Texto curto: "Incluir 17:00", "Excluir 13:05", "Alterar 08:30 → 08:00". */
    public function summary(): string
    {
        $original = $this->timeEntry?->marked_at->format('H:i');
        $proposed = $this->proposed_at?->format('H:i');

        return match ($this->type) {
            'inclusao' => "Incluir {$proposed}",
            'desconsideracao' => "Excluir {$original}",
            'alteracao' => "Alterar {$original} → {$proposed}",
            default => $this->type,
        };
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? $this->type,
            'time_entry_id' => $this->time_entry_id,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'summary' => $this->summary(),
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
