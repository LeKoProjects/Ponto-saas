<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToCompany, HasFactory;

    protected $attributes = ['active' => true];

    protected $fillable = ['company_id', 'user_id', 'cpf', 'registration', 'admission_date', 'work_schedule_id', 'team_id', 'active'];

    protected function casts(): array
    {
        return ['admission_date' => 'date', 'active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TimeAdjustment::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function maskedCpf(): string
    {
        $c = $this->cpf;

        return substr($c, 0, 3).'.'.substr($c, 3, 3).'.'.substr($c, 6, 3).'-'.substr($c, 9, 2);
    }
}
