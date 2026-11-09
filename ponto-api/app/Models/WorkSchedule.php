<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    use BelongsToCompany, HasFactory;

    public const WEEKDAYS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    protected $fillable = ['company_id', 'name', 'start_time', 'end_time', 'daily_minutes', 'break_minutes', 'weekdays', 'tolerance_minutes'];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'daily_minutes' => 'integer',
            'break_minutes' => 'integer',
            'tolerance_minutes' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function worksOn(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', $this->weekdays ?? []), true);
    }
}
