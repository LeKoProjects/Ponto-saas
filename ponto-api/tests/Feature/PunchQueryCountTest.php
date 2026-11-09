<?php

use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\ContextCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/* Requisição de batida enxuta: contexto em cache, idempotência e resposta completa. */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Teta');
    $this->token = apiToken($this->c['employee']->user);
});

it('registra a batida com no máximo 6 consultas ao banco (contexto em cache)', function () {
    $this->withToken($this->token)->postJson('/api/ponto')->assertCreated(); // aquece o cache

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withToken($this->token)->postJson('/api/ponto')->assertCreated();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // lock companies, contagem do dia, insert, update companies, marcações de hoje (+ margem 1)
    expect($queries->count())->toBeLessThanOrEqual(6)
        ->and($queries->filter(fn ($q) => str_contains($q, 'from "users"') || str_contains($q, 'from "employees"')))->toBeEmpty();
});

it('devolve na resposta tudo que a tela precisa, sem chamar /me de novo', function () {
    $this->withToken($this->token)->postJson('/api/ponto')->assertCreated();

    $this->withToken($this->token)->postJson('/api/ponto')
        ->assertCreated()
        ->assertJsonCount(2, 'today')
        ->assertJsonPath('next_type', 'intervalo_fim')
        ->assertJsonPath('next_type_label', 'Fim do intervalo')
        ->assertJsonPath('receipt.trabalhador', $this->c['employee']->user->name)
        ->assertJsonPath('receipt.cnpj', $this->c['company']->cnpj)
        ->assertJsonPath('replayed', false);
});

it('não duplica a batida quando o app reenvia o mesmo request_id', function () {
    $id = (string) Str::uuid();

    $first = $this->withToken($this->token)->postJson('/api/ponto', ['request_id' => $id])->assertCreated();
    $retry = $this->withToken($this->token)->postJson('/api/ponto', ['request_id' => $id])->assertOk();

    expect($retry->json('replayed'))->toBeTrue()
        ->and($retry->json('entry.nsr'))->toBe($first->json('entry.nsr'))
        ->and(TimeEntry::count())->toBe(1);
    Mail::assertQueuedCount(1);

    $this->withToken($this->token)->postJson('/api/ponto', ['request_id' => (string) Str::uuid()])->assertCreated();
    expect(TimeEntry::count())->toBe(2);
});

it('mantém a cadeia de hash íntegra usando companies.last_hash', function () {
    for ($i = 0; $i < 10; $i++) {
        app(TimeEntryService::class)->register($this->c['employee']);
    }

    expect(app(TimeEntryService::class)->verifyChain($this->c['company']->id))->toBe([])
        ->and(DB::table('companies')->where('id', $this->c['company']->id)->value('last_hash'))
        ->toBe(TimeEntry::forCompany($this->c['company']->id)->orderByDesc('nsr')->value('hash'));
});

it('atualiza o contexto em cache quando o RH muda dados do colaborador ou da empresa', function () {
    $user = $this->c['employee']->user;
    expect(ContextCache::employee($user)['require_location'])->toBeFalse();

    $this->c['company']->update(['require_location' => true]);
    expect(ContextCache::employee($user)['require_location'])->toBeTrue();

    $user->update(['name' => 'Nome Novo']);
    expect(ContextCache::employee($user->fresh())['name'])->toBe('Nome Novo');

    $this->c['employee']->update(['active' => false]);
    // Desativado: o token do app é apagado na hora (o app volta ao login).
    $this->withToken($this->token)->postJson('/api/ponto', ['latitude' => -12.9, 'longitude' => -38.5])->assertUnauthorized();

    $this->c['schedule']->update(['start_time' => '07:00']);
    expect(ContextCache::employee($user->fresh())['schedule']['start'])->toBe('07:00');
});

it('expõe o perfil no /me para o app guardar localmente', function () {
    $this->withToken($this->token)->postJson('/api/me')
        ->assertOk()
        ->assertJsonPath('profile.employee_id', $this->c['employee']->id)
        ->assertJsonPath('profile.name', $this->c['employee']->user->name)
        ->assertJsonPath('profile.cnpj', $this->c['company']->cnpj)
        ->assertJsonPath('profile.require_location', false)
        ->assertJsonStructure(['profile' => ['cpf', 'company', 'schedule' => ['name', 'start', 'end']]]);
});
