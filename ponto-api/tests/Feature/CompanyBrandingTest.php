<?php

use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/* Logo por empresa e política de privacidade. */

beforeEach(function () {
    Storage::fake('public');
    $this->c = makeCompany('Nu');
});

it('RH envia a logo e ela aparece no painel e no app', function () {
    $this->actingAs($this->c['rh'])
        ->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)])
        ->assertSessionHasNoErrors();

    $company = $this->c['company']->fresh();
    Storage::disk('public')->assertExists($company->logo_path);
    expect($company->logo_path)->toStartWith("logos/{$company->id}/")
        ->and(AuditLog::where('action', 'company.logo_updated')->exists())->toBeTrue()
        ->and($company->logoDataUri())->toStartWith('data:image/png;base64,');

    $this->actingAs($this->c['rh'])->get('/configuracoes')
        ->assertInertia(fn ($p) => $p->where('auth.company.logo_url', $company->logoUrl())->where('settings.logo_url', $company->logoUrl()));

    app('auth')->forgetGuards();
    $this->withToken(apiToken($this->c['employee']->user))->postJson('/api/me')
        ->assertJsonPath('profile.logo_url', $company->logoUrl())
        ->assertJsonPath('profile.company_id', $company->id);
});

it('troca e remove a logo apagando o arquivo antigo', function () {
    $this->actingAs($this->c['rh'])->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('a.png', 200, 200)]);
    $first = $this->c['company']->fresh()->logo_path;

    $this->actingAs($this->c['rh'])->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('b.jpg', 200, 200)]);
    $second = $this->c['company']->fresh()->logo_path;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->actingAs($this->c['rh'])->post('/configuracoes/logo/remover')->assertSessionHasNoErrors();
    Storage::disk('public')->assertMissing($second);
    expect($this->c['company']->fresh()->logo_path)->toBeNull();
});

it('recusa arquivo que não é imagem, grande demais ou de quem não é RH', function () {
    $this->actingAs($this->c['rh'])->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('logo');
    $this->actingAs($this->c['rh'])->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('big.png', 400, 400)->size(2048)])
        ->assertSessionHasErrors('logo');
    $this->actingAs($this->c['employee']->user)->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('a.png', 200, 200)])
        ->assertForbidden();

    expect($this->c['company']->fresh()->logo_path)->toBeNull();
});

it('logo de uma empresa não afeta a outra', function () {
    $other = makeCompany('Xi');
    $this->actingAs($this->c['rh'])->post('/configuracoes/logo', ['logo' => UploadedFile::fake()->image('a.png', 200, 200)]);

    expect($other['company']->fresh()->logo_path)->toBeNull();
});

it('mostra a política de privacidade pública com o encarregado da empresa', function () {
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => false, 'privacy_email' => 'dpo@nu.test'])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get('/privacidade')->assertOk()->assertInertia(fn ($p) => $p->component('Public/Privacy')->where('company', null));
    $this->get('/privacidade?empresa='.$this->c['company']->id)
        ->assertInertia(fn ($p) => $p->where('company.privacy_email', 'dpo@nu.test')->where('company.cnpj', $this->c['company']->cnpj)->missing('company.id'));
});
