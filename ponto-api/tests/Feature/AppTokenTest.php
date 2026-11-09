<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/* Segurança do token do app. */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Mi');
    $this->user = $this->c['employee']->user;
});

it('emite token com prefixo e ligado ao aparelho', function () {
    $token = $this->postJson('/api/login', [
        'email' => $this->user->email, 'password' => 'password',
        'device_id' => (string) Str::uuid(), 'device_name' => 'Pixel',
    ])->json('token');

    expect($token)->toContain('|ponto_')
        ->and(PersonalAccessToken::first()->device_id)->not->toBeNull()
        ->and(PersonalAccessToken::first()->abilities)->toBe(['ponto']);
});

it('expira o token parado por mais tempo que o limite e renova quem usa', function () {
    config(['ponto.token_idle_days' => 30]);
    $token = apiToken($this->user);

    Carbon::setTestNow(now()->addDays(20));
    $this->withToken($token)->postJson('/api/me')->assertOk(); // uso renova o prazo

    Carbon::setTestNow(now()->addDays(25)); // 25 dias depois do último uso: ainda vale
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/me')->assertOk();

    Carbon::setTestNow(now()->addDays(31)); // parado há 31 dias: expirou
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/me')->assertUnauthorized();

    $this->artisan('ponto:limpar-tokens')->assertSuccessful();
    expect(PersonalAccessToken::count())->toBe(0);
    Carbon::setTestNow();
});

it('recusa token sem a permissão do app', function () {
    $other = $this->user->createToken('outro', ['relatorios'])->plainTextToken;

    $this->withToken($other)->postJson('/api/aparelho')->assertForbidden();
});

it('desativar o colaborador derruba o acesso do app na hora', function () {
    $token = apiToken($this->user);

    $this->actingAs($this->c['rh'])->post('/funcionarios/'.$this->c['employee']->id, [
        'name' => $this->user->name,
        'email' => $this->user->email,
        'cpf' => $this->c['employee']->cpf,
        'admission_date' => $this->c['employee']->admission_date->toDateString(),
        'work_schedule_id' => $this->c['employee']->work_schedule_id,
        'role' => 'funcionario',
        'active' => false,
    ])->assertSessionHasNoErrors();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/me')->assertUnauthorized();
});
