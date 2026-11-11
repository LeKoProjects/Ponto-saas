<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory;

    public const PLANS = [
        'essencial' => ['label' => 'Essencial', 'price' => 49, 'max_employees' => 10],
        'profissional' => ['label' => 'Profissional', 'price' => 99, 'max_employees' => 30],
        'empresa' => ['label' => 'Empresa', 'price' => 199, 'max_employees' => 80],
    ];

    protected $fillable = ['cnpj', 'legal_name', 'trade_name', 'plan', 'status', 'timezone', 'require_location', 'logo_path', 'privacy_email', 'geofence_enabled'];

    protected $casts = ['require_location' => 'boolean', 'geofence_enabled' => 'boolean'];

    protected $hidden = ['next_nsr', 'last_hash'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class)->withoutGlobalScope('company');
    }

    public function workLocations(): HasMany
    {
        return $this->hasMany(WorkLocation::class)->withoutGlobalScope('company');
    }

    /** Cerca virtual em vigor: ligada e com pelo menos um local cadastrado. */
    public function geofenceActive(): bool
    {
        return $this->geofence_enabled && $this->workLocations()->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function maxEmployees(): int
    {
        return self::PLANS[$this->plan]['max_employees'] ?? 10;
    }

    /** URL pública da logo (painel, app e e-mail). */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    /** Logo embutida (data URI) para o PDF — o DomPDF não precisa buscar arquivo nem URL. */
    public function logoDataUri(): ?string
    {
        if (! $this->logo_path || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }
        $disk = Storage::disk('public');

        return 'data:'.$disk->mimeType($this->logo_path).';base64,'.base64_encode($disk->get($this->logo_path));
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }
}
