<?php

use App\Mail\TimeEntryReceiptMail;
use App\Models\Device;
use App\Models\TimeEntry;
use App\Services\DeviceService;
use App\Services\TimeEntryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/* Sprint 3 — "100 marcações seguidas sem NSR duplicado; UPDATE em time_entries bloqueado no banco". */

beforeEach(function () {
    $this->c = makeCompany('Gama');
    $this->service = app(TimeEntryService::class);
});

it('gera 100 marcações com NSR sequencial, sem duplicidade e cadeia de hash íntegra', function () {
    for ($i = 0; $i < 100; $i++) {
        $this->service->register($this->c['employee']);
    }

    $nsrs = TimeEntry::forCompany($this->c['company']->id)->orderBy('nsr')->pluck('nsr')->all();

    expect($nsrs)->toHaveCount(100)
        ->and($nsrs)->toBe(range(1, 100))
        ->and($this->service->verifyChain($this->c['company']->id))->toBe([]);
});

it('bloqueia UPDATE em time_entries direto no banco', function () {
    $entry = $this->service->register($this->c['employee']);

    DB::update('UPDATE time_entries SET marked_at = marked_at - interval \'1 hour\' WHERE id = ?', [$entry->id]);
})->throws(QueryException::class, 'somente INSERT');

it('bloqueia DELETE em time_entries direto no banco', function () {
    $entry = $this->service->register($this->c['employee']);

    DB::delete('DELETE FROM time_entries WHERE id = ?', [$entry->id]);
})->throws(QueryException::class, 'somente INSERT');

it('recusa alteração pelo Eloquent antes de chegar ao banco', function () {
    $entry = $this->service->register($this->c['employee']);
    $entry->type = 'saida';
    $entry->save();
})->throws(LogicException::class);

it('detecta adulteração na cadeia de hash', function () {
    $this->service->register($this->c['employee']);
    $this->service->register($this->c['employee']);

    // Simula adulteração por fora (desliga o trigger só nesta transação de teste).
    DB::statement('ALTER TABLE time_entries DISABLE TRIGGER time_entries_immutable');
    DB::update('UPDATE time_entries SET marked_at = marked_at - interval \'2 hours\' WHERE nsr = 1 AND company_id = ?', [$this->c['company']->id]);
    DB::statement('ALTER TABLE time_entries ENABLE TRIGGER time_entries_immutable');

    expect($this->service->verifyChain($this->c['company']->id))->toBe([1]);
});

it('usa a hora do servidor e sequencia os tipos do dia', function () {
    Carbon::setTestNow('2026-11-09 08:01:30');
    $e1 = $this->service->register($this->c['employee']);
    Carbon::setTestNow('2026-11-09 12:00:00');
    $e2 = $this->service->register($this->c['employee']);
    Carbon::setTestNow();

    expect($e1->marked_at->format('Y-m-d H:i:s'))->toBe('2026-11-09 08:01:30')
        ->and($e1->type)->toBe('entrada')
        ->and($e2->type)->toBe('intervalo_inicio');
});

it('registra ponto pela API da PWA, devolve comprovante e envia por e-mail', function () {
    Mail::fake();
    $user = $this->c['employee']->user;

    $login = $this->postJson('/api/login', [
        'email' => $user->email, 'password' => 'password',
        'device_id' => (string) Str::uuid(), 'device_name' => 'Pixel 8 · Android 14',
    ])->assertOk()->assertJsonPath('device.status', 'pendente');
    $token = $login->json('token');

    // RH libera o aparelho.
    app(DeviceService::class)->approve(Device::findOrFail($login->json('device.id')), $this->c['rh']);

    $this->withToken($token)
        ->postJson('/api/ponto', ['latitude' => -12.9714, 'longitude' => -38.5014, 'marked_at' => '2000-01-01 00:00:00'])
        ->assertCreated()
        ->assertJsonPath('receipt.nsr', 1)
        ->assertJsonPath('receipt.cnpj', $this->c['company']->cnpj)
        ->assertJsonPath('receipt.trabalhador', $user->name)
        ->assertJsonStructure(['receipt' => ['hash', 'data', 'hora', 'cpf', 'empregador']]);

    // O horário enviado pelo cliente é ignorado.
    expect(TimeEntry::first()->marked_at->year)->toBe(now()->year);
    Mail::assertQueued(TimeEntryReceiptMail::class);

    $this->withToken($token)->postJson('/api/me')->assertOk()->assertJsonCount(1, 'today')->assertJsonPath('next_type', 'intervalo_inicio');
});

it('aplica rate limit no endpoint de ponto', function () {
    Mail::fake();
    $this->withToken(apiToken($this->c['employee']->user));

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/ponto')->assertCreated();
    }
    $this->postJson('/api/ponto')->assertStatus(429);
});

it('não permite login na PWA de usuário sem cadastro de funcionário', function () {
    $this->postJson('/api/login', ['email' => $this->c['rh']->email, 'password' => 'password', 'device_id' => (string) Str::uuid(), 'device_name' => 'Teste'])
        ->assertUnprocessable();
});
