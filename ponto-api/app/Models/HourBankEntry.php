<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lançamento do banco de horas (fase 2). Saldo = soma de minutes. */
class HourBankEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'hour_bank_ledger';

    protected $fillable = ['company_id', 'employee_id', 'date', 'minutes', 'origin', 'description'];

    protected function casts(): array
    {
        return ['date' => 'date', 'minutes' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
