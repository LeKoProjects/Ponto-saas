<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marcação de ponto (REP-P). Somente INSERT: o banco bloqueia UPDATE/DELETE
 * via trigger e o model também recusa antes de chegar lá.
 */
class TimeEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const TYPES = [
        'entrada' => 'Entrada',
        'intervalo_inicio' => 'Início do intervalo',
        'intervalo_fim' => 'Fim do intervalo',
        'saida' => 'Saída',
        'extra' => 'Marcação extra',
    ];

    protected $fillable = ['company_id', 'employee_id', 'nsr', 'marked_at', 'type', 'latitude', 'longitude', 'ip', 'device', 'request_id', 'prev_hash', 'hash'];

    protected function casts(): array
    {
        return ['marked_at' => 'datetime', 'nsr' => 'integer', 'latitude' => 'float', 'longitude' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Marcações de ponto não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Marcações de ponto não podem ser excluídas.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** hash = sha256(prev_hash | employee_id | nsr | marked_at ISO-8601) */
    public static function computeHash(string $prevHash, int $employeeId, int $nsr, string $markedAtIso): string
    {
        return hash('sha256', $prevHash.'|'.$employeeId.'|'.$nsr.'|'.$markedAtIso);
    }
}
