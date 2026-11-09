<?php

use App\Models\AuditLog;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Mail;

/* Localização: parâmetro da empresa definido pelo RH. Ativo = obrigatório; inativo = não coletado. */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Eta');
});

it('RH ativa a exigência de localização e a mudança é auditada', function () {
    $this->actingAs($this->c['rh'])->get('/configuracoes')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Settings/Index')->where('settings.require_location', false));

    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => true])->assertSessionHasNoErrors();

    expect($this->c['company']->fresh()->require_location)->toBeTrue()
        ->and(AuditLog::where('action', 'company.settings_updated')->exists())->toBeTrue();
});

it('gestor e colaborador não alteram a configuração', function () {
    $this->actingAs($this->c['employee']->user)->post('/configuracoes', ['require_location' => true])->assertForbidden();
    expect($this->c['company']->fresh()->require_location)->toBeFalse();
});

it('com localização obrigatória, recusa batida sem GPS e aceita com GPS', function () {
    $this->c['company']->update(['require_location' => true]);
    $this->withToken(apiToken($this->c['employee']->user));

    $this->postJson('/api/me')->assertJsonPath('require_location', true);

    $this->postJson('/api/ponto')->assertJsonValidationErrors(['latitude', 'longitude']);
    $this->postJson('/api/ponto', ['latitude' => -12.97])->assertJsonValidationErrors('longitude');
    expect(TimeEntry::count())->toBe(0);

    $this->postJson('/api/ponto', ['latitude' => -12.9714, 'longitude' => -38.5014])->assertCreated();
    expect(TimeEntry::first()->latitude)->toBe(-12.9714);
});

it('com localização desativada, registra sem GPS e descarta coordenadas enviadas', function () {
    $this->withToken(apiToken($this->c['employee']->user));

    $this->postJson('/api/me')->assertJsonPath('require_location', false);
    $this->postJson('/api/ponto')->assertCreated();
    $this->postJson('/api/ponto', ['latitude' => -12.9714, 'longitude' => -38.5014])->assertCreated();

    expect(TimeEntry::whereNotNull('latitude')->count())->toBe(0);
});
