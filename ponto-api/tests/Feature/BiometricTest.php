<?php

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\TimeEntry;
use App\Services\DeviceService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeAuthenticator;

/* Biometria do celular (WebAuthn): cadastro no aparelho liberado e confirmação obrigatória na batida. */

beforeEach(function () {
    Mail::fake();
    config(['ponto.pwa_url' => 'http://localhost:5173', 'ponto.webauthn.rp_id' => null]);
    $this->c = makeCompany('Upsilon');
    $this->user = $this->c['employee']->user;
    $this->token = apiToken($this->user, 'Pixel 8 · Chrome');
    $this->device = Device::latest('id')->first();
    $this->phone = new FakeAuthenticator;
});

/** Liga a biometria pelo app: opções → celular cria a credencial → servidor guarda. */
function enableBiometric($test): void
{
    $opts = $test->withToken($test->token)->postJson('/api/biometria/opcoes')->assertOk()->json();
    expect($opts['publicKey']['rp']['id'])->toBe('localhost')
        ->and($opts['publicKey']['authenticatorSelection']['userVerification'])->toBe('required');

    $test->postJson('/api/biometria/cadastrar', $test->phone->create($opts))
        ->assertOk()
        ->assertJsonPath('device.biometric', true);
}

/** Zera o limite de 4 batidas/min entre os casos (os desafios são pedidos de novo depois). */
function resetPunchLimit(): void
{
    cache()->store()->flush();
}

function signedPunch($test, array $extra = [])
{
    $opts = $test->withToken($test->token)->postJson('/api/biometria/desafio')->assertOk()->json();

    return $test->postJson('/api/ponto', ['webauthn' => $test->phone->get($opts), ...$extra]);
}

it('cadastra a biometria no aparelho e guarda só a chave pública', function () {
    enableBiometric($this);

    $device = $this->device->fresh();
    expect($device->webauthn_credential_id)->toBe(FakeAuthenticator::b64url($this->phone->credentialId))
        ->and($device->webauthn_public_key)->toContain('BEGIN PUBLIC KEY')
        ->and(AuditLog::where('action', 'device.biometric_registered')->value('user_id'))->toBe($this->user->id);

    // A chave pública nunca sai na API.
    $this->postJson('/api/me')
        ->assertJsonPath('device.biometric', true)
        ->assertJsonMissingPath('device.webauthn_public_key');
});

it('com biometria cadastrada, a batida exige a assinatura do celular', function () {
    enableBiometric($this);

    $this->postJson('/api/ponto')->assertUnprocessable()->assertJsonValidationErrors('biometria');
    expect(TimeEntry::count())->toBe(0);

    signedPunch($this)->assertCreated();
    signedPunch($this)->assertCreated();
    expect(TimeEntry::count())->toBe(2)
        ->and($this->device->fresh()->webauthn_sign_count)->toBe(2);
});

it('recusa assinatura reaproveitada, adulterada, sem verificação do usuário ou de outro domínio', function () {
    enableBiometric($this);

    // Mesma assinatura duas vezes: o desafio é de uso único.
    $opts = $this->postJson('/api/biometria/desafio')->json();
    $assertion = $this->phone->get($opts);
    $this->postJson('/api/ponto', ['webauthn' => $assertion])->assertCreated();
    $this->postJson('/api/ponto', ['webauthn' => $assertion])->assertJsonValidationErrors('biometria');

    resetPunchLimit();
    // Assinatura adulterada.
    $opts = $this->postJson('/api/biometria/desafio')->json();
    $bad = $this->phone->get($opts);
    $bad['signature'] = FakeAuthenticator::b64url(random_bytes(70));
    $this->postJson('/api/ponto', ['webauthn' => $bad])->assertJsonValidationErrors('biometria');

    resetPunchLimit();
    // Sem verificação do usuário (só presença): não vale como biometria.
    $opts = $this->postJson('/api/biometria/desafio')->json();
    $this->postJson('/api/ponto', ['webauthn' => $this->phone->get($opts, 0x01)])->assertJsonValidationErrors('biometria');

    resetPunchLimit();
    // Página de outro domínio tentando usar a credencial.
    $opts = $this->postJson('/api/biometria/desafio')->json();
    $this->postJson('/api/ponto', ['webauthn' => $this->phone->get($opts, 0x05, 'https://site-falso.com')])->assertJsonValidationErrors('biometria');

    resetPunchLimit();
    // Outro "celular" (outra chave) não passa.
    $opts = $this->postJson('/api/biometria/desafio')->json();
    $this->postJson('/api/ponto', ['webauthn' => (new FakeAuthenticator)->get($opts)])->assertJsonValidationErrors('biometria');

    expect(TimeEntry::count())->toBe(1);
});

it('reenvio da mesma batida (request_id) não pede biometria de novo', function () {
    enableBiometric($this);
    $id = (string) Str::uuid();

    signedPunch($this, ['request_id' => $id])->assertCreated();
    $this->postJson('/api/ponto', ['request_id' => $id])->assertOk()->assertJsonPath('replayed', true);

    expect(TimeEntry::count())->toBe(1);
});

it('desligar a biometria volta a aceitar a batida sem confirmação', function () {
    enableBiometric($this);
    $this->postJson('/api/biometria/desativar')->assertOk()->assertJsonPath('device.biometric', false);

    $this->postJson('/api/ponto')->assertCreated();
    expect(AuditLog::where('action', 'device.biometric_removed')->exists())->toBeTrue()
        ->and($this->device->fresh()->webauthn_public_key)->toBeNull();
});

it('liberar outro aparelho apaga a biometria do antigo', function () {
    enableBiometric($this);

    $new = Device::create([
        'company_id' => $this->c['company']->id, 'employee_id' => $this->c['employee']->id,
        'uuid' => (string) Str::uuid(), 'name' => 'Celular novo', 'status' => 'pendente', 'requested_at' => now(),
    ]);
    app(DeviceService::class)->approve($new, $this->c['rh']);

    expect($this->device->fresh()->status)->toBe('revogado')
        ->and($this->device->fresh()->webauthn_credential_id)->toBeNull();
});

it('só aparelho liberado acessa a biometria e o desafio exige cadastro', function () {
    $this->withToken($this->token)->postJson('/api/biometria/desafio')->assertJsonValidationErrors('biometria');

    $pending = $this->postJson('/api/login', [
        'email' => $this->user->email, 'password' => 'password',
        'device_id' => (string) Str::uuid(), 'device_name' => 'Outro celular',
    ])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($pending)->postJson('/api/biometria/opcoes')->assertForbidden();
});
