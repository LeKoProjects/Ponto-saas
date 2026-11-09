<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    public const PLANS = [
        'essencial' => ['label' => 'Essencial', 'price' => 49, 'max_employees' => 10],
        'profissional' => ['label' => 'Profissional', 'price' => 99, 'max_employees' => 30],
        'empresa' => ['label' => 'Empresa', 'price' => 199, 'max_employees' => 80],
    ];

    protected $fillable = ['cnpj', 'legal_name', 'trade_name', 'plan', 'status', 'timezone'];

    protected $hidden = ['next_nsr'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class)->withoutGlobalScope('company');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function maxEmployees(): int
    {
        return self::PLANS[$this->plan]['max_employees'] ?? 10;
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }
}
