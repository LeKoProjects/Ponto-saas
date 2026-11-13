<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aparelho do colaborador. Só um "aprovado" por colaborador; os demais ficam como histórico. */
class Device extends Model
{
    use BelongsToCompany;

    public const STATUSES = [
        'pendente' => 'Aguardando liberação',
        'aprovado' => 'Liberado',
        'recusado' => 'Recusado',
        'revogado' => 'Desconectado',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'uuid', 'name', 'user_agent', 'status', 'requested_at',
        'decided_at', 'decided_by', 'revoked_at', 'revoked_by', 'revoke_reason', 'last_seen_at', 'last_ip',
    ];

    /** Chave pública da biometria (WebAuthn): nunca vai para respostas da API nem para o painel. */
    protected $hidden = ['webauthn_public_key'];

    protected function casts(): array
    {
        return [
            'webauthn_registered_at' => 'datetime',
            'webauthn_sign_count' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function hasBiometric(): bool
    {
        return $this->webauthn_credential_id !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'aprovado';
    }

    /** Para o app do colaborador. */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'requested_at' => $this->requested_at?->format('d/m/Y H:i'),
            'decided_at' => $this->decided_at?->format('d/m/Y H:i'),
            'revoke_reason' => $this->revoke_reason,
            // Biometria do celular cadastrada neste aparelho: o servidor exige a confirmação na batida.
            'biometric' => $this->hasBiometric(),
            'biometric_since' => $this->webauthn_registered_at?->format('d/m/Y H:i'),
        ];
    }
}
