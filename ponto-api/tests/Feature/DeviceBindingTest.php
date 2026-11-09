<?php

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Login limitado a um aparelho:
 * 1º acesso no aparelho → pedido ao RH; RH libera → só ele bate ponto, os outros são desconectados.
 * Nada é apagado: devices + audit_logs são o histórico.
 */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Iota');
    $this->user = $this->c['employee']->user;
});

function loginFrom($test, string $uuid, string $name = 'Pixel 8 · Android 14')
{
    return $test->postJson('/api/login', [
        'email' => $test->user->email,
        'password' => 'password',
        'device_id' => $uuid,
        'device_name' => $name,
    ]);
}

it('primeiro login no aparelho fica aguardando o RH e não bate ponto', function () {
    $res = loginFrom($this, (string) Str::uuid())
        ->assertOk()
        ->assertJsonPath('device.status', 'pendente')
        ->assertJsonPath('device.name', 'Pixel 8 · Android 14');

    $this->withToken($res->json('token'));
    $this->postJson('/api/ponto')->assertForbidden()->assertJsonPath('code', 'device_pendente');
    $this->postJson('/api/me')->assertForbidden();
    $this->postJson('/api/aparelho')->assertOk()->assertJsonPath('device.status', 'pendente');

    expect(TimeEntry::count())->toBe(0)
        ->and(AuditLog::where('action', 'device.requested')->value('user_id'))->toBe($this->user->id);
});

it('RH libera o aparelho pelo painel e o app passa a bater ponto', function () {
    $res = loginFrom($this, (string) Str::uuid());
    $device = Device::findOrFail($res->json('device.id'));

    $this->actingAs($this->c['rh'])->get('/aparelhos')
        ->assertInertia(fn ($p) => $p->component('Devices/Index')->has('devices.data', 1)->where('pending_devices', 1));
    $this->actingAs($this->c['rh'])->post("/aparelhos/{$device->id}/liberar")->assertSessionHasNoErrors();

    app('auth')->forgetGuards(); // o app não tem a sessão do RH
    $this->withToken($res->json('token'))->postJson('/api/ponto')->assertCreated();
    $this->postJson('/api/aparelho')->assertJsonPath('device.status', 'aprovado');
    expect($device->fresh()->decided_by)->toBe($this->c['rh']->id);
});

it('liberar um aparelho novo desconecta o antigo e mantém o histórico', function () {
    $oldUuid = (string) Str::uuid();
    $old = loginFrom($this, $oldUuid, 'Galaxy A54');
    $this->actingAs($this->c['rh'])->post('/aparelhos/'.$old->json('device.id').'/liberar');
    $oldToken = $old->json('token');

    // Colaborador entra num celular novo: o antigo continua funcionando até o RH decidir.
    $new = loginFrom($this, (string) Str::uuid(), 'Pixel 8');
    $new->assertJsonPath('device.status', 'pendente');
    app('auth')->forgetGuards();
    $this->withToken($oldToken)->postJson('/api/ponto')->assertCreated();

    $this->actingAs($this->c['rh'])->get('/aparelhos')
        ->assertInertia(fn ($p) => $p->where('devices.data.0.replaces', 'Galaxy A54'));
    $this->actingAs($this->c['rh'])->post('/aparelhos/'.$new->json('device.id').'/liberar');

    // Antigo: token apagado (app recebe 401) e aparelho "revogado", não excluído.
    app('auth')->forgetGuards();
    $this->withToken($oldToken)->postJson('/api/ponto')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($new->json('token'))->postJson('/api/ponto')->assertCreated();

    $oldDevice = Device::where('uuid', $oldUuid)->first();
    expect($oldDevice->status)->toBe('revogado')
        ->and($oldDevice->revoke_reason)->toContain('Pixel 8')
        ->and(Device::where('employee_id', $this->c['employee']->id)->count())->toBe(2)
        ->and(Device::where('employee_id', $this->c['employee']->id)->where('status', 'aprovado')->count())->toBe(1)
        ->and(AuditLog::where('action', 'device.revoked')->exists())->toBeTrue();

    // Histórico aparece no cadastro do funcionário.
    $this->actingAs($this->c['rh'])->get('/funcionarios/'.$this->c['employee']->id)
        ->assertInertia(fn ($p) => $p->has('devices', 2));
});

it('liberar um aparelho recusa os outros pedidos pendentes do colaborador', function () {
    $a = loginFrom($this, (string) Str::uuid(), 'Celular A');
    $b = loginFrom($this, (string) Str::uuid(), 'Celular B');

    $this->actingAs($this->c['rh'])->post('/aparelhos/'.$a->json('device.id').'/liberar');

    expect(Device::find($b->json('device.id'))->status)->toBe('recusado')
        ->and(PersonalAccessToken::where('device_id', $b->json('device.id'))->exists())->toBeFalse();
});

it('relogar no mesmo aparelho liberado entra direto, sem novo pedido', function () {
    $uuid = (string) Str::uuid();
    $first = loginFrom($this, $uuid);
    $this->actingAs($this->c['rh'])->post('/aparelhos/'.$first->json('device.id').'/liberar');

    app('auth')->forgetGuards();
    $again = loginFrom($this, $uuid)->assertJsonPath('device.status', 'aprovado');

    expect(Device::count())->toBe(1)
        ->and(PersonalAccessToken::where('device_id', $again->json('device.id'))->count())->toBe(1); // token antigo substituído
});

it('RH recusa ou desconecta, e o aparelho pode pedir de novo', function () {
    $uuid = (string) Str::uuid();
    $res = loginFrom($this, $uuid);
    $id = $res->json('device.id');

    $this->actingAs($this->c['rh'])->post("/aparelhos/{$id}/recusar", ['reason' => 'Aparelho pessoal'])->assertSessionHasNoErrors();
    expect(Device::find($id)->status)->toBe('recusado');
    app('auth')->forgetGuards();
    $this->withToken($res->json('token'))->postJson('/api/aparelho')->assertUnauthorized();

    // Pede de novo no mesmo aparelho → volta a pendente (mesmo registro, histórico na auditoria).
    app('auth')->forgetGuards();
    loginFrom($this, $uuid)->assertJsonPath('device.status', 'pendente');
    $this->actingAs($this->c['rh'])->post("/aparelhos/{$id}/liberar");
    $this->actingAs($this->c['rh'])->post("/aparelhos/{$id}/desconectar", ['reason' => 'Celular perdido']);

    expect(Device::find($id)->status)->toBe('revogado')
        ->and(Device::find($id)->revoke_reason)->toBe('Celular perdido')
        ->and(AuditLog::where('entity_type', 'Device')->where('entity_id', $id)->pluck('action')->all())
        ->toBe(['device.requested', 'device.rejected', 'device.requested', 'device.approved', 'device.revoked']);
});

it('exige identificação do aparelho no login e bloqueia tokens antigos sem aparelho', function () {
    $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertJsonValidationErrors(['device_id', 'device_name']);

    $legacy = $this->user->createToken('antigo', ['ponto'])->plainTextToken;
    $this->withToken($legacy)->postJson('/api/ponto')->assertForbidden()->assertJsonPath('code', 'device_required');
});

it('RH de outra empresa não vê nem libera aparelhos', function () {
    $res = loginFrom($this, (string) Str::uuid());
    $other = makeCompany('Kapa');

    $this->actingAs($other['rh'])->get('/aparelhos')->assertInertia(fn ($p) => $p->has('devices.data', 0));
    $this->actingAs($other['rh'])->post('/aparelhos/'.$res->json('device.id').'/liberar')->assertNotFound();
    $this->actingAs($this->c['employee']->user)->get('/aparelhos')->assertForbidden();

    expect(Device::find($res->json('device.id'))->status)->toBe('pendente');
});
