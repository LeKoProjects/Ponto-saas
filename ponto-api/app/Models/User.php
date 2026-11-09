<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuários não usam o global scope de empresa: o login precisa achar o
 * usuário antes de existir tenant. As consultas do painel filtram por
 * company_id explicitamente.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = [
        'super_admin' => 'Admin SaaS',
        'owner' => 'Dono',
        'rh' => 'RH',
        'gestor' => 'Gestor',
        'funcionario' => 'Funcionário',
    ];

    protected $fillable = ['company_id', 'role', 'name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class)->withoutGlobalScope('company');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Pode gerenciar cadastros da empresa. */
    public function canManage(): bool
    {
        return $this->hasRole('owner', 'rh');
    }
}
