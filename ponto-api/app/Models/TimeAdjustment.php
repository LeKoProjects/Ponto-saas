<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tratamento sobre a marcação original (inclusão ou desconsideração). */
class TimeAdjustment extends Model
{
    use BelongsToCompany;

    public const TYPES = ['inclusao' => 'Inclusão', 'desconsideracao' => 'Desconsideração'];

    public const STATUSES = ['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'rejeitado' => 'Rejeitado'];

    protected $fillable = ['company_id', 'employee_id', 'time_entry_id', 'date', 'type', 'proposed_at', 'reason', 'attachment_path', 'status', 'requested_by', 'approved_by', 'decided_at'];

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
}
