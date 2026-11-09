<?php

use App\Models\Team;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\MonthlyReportService;
use App\Services\TimeEntryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/* Colaborador solicita ajustes pela PWA; RH/gestor aprova ou rejeita no painel. */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Épsilon');
    $this->employee = $this->c['employee'];

    // Segunda-feira, 09/11/2026: 08:00, 12:00, 13:00, 13:05 (duplicada) — falta a saída.
    $service = app(TimeEntryService::class);
    foreach (['08:00', '12:00', '13:00', '13:05'] as $t) {
        Carbon::setTestNow("2026-11-09 {$t}:00");
        $this->entries[] = $service->register($this->employee);
    }
    Carbon::setTestNow('2026-11-10 09:00:00');

    $this->actingAs($this->employee->user, 'sanctum');
});

afterEach(fn () => Carbon::setTestNow());

$day = fn ($employee) => collect(app(MonthlyReportService::class)->forEmployee($employee, 2026, 11)['days'])->firstWhere('date', '2026-11-09');

it('cria solicitações pendentes de adicionar, excluir e alterar sem mudar o cálculo', function () use ($day) {
    $before = $day($this->employee)['worked_minutes'];

    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Esqueci de registrar a saída'])
        ->assertCreated()->assertJsonPath('request.status', 'pendente')->assertJsonPath('request.summary', 'Incluir 17:00');
    $this->postJson('/api/ajustes', ['type' => 'desconsideracao', 'time_entry_id' => $this->entries[3]->id, 'reason' => 'Bati duas vezes'])
        ->assertCreated()->assertJsonPath('request.summary', 'Excluir 13:05');
    $this->postJson('/api/ajustes', ['type' => 'alteracao', 'time_entry_id' => $this->entries[0]->id, 'time' => '07:55', 'reason' => 'Cheguei antes, o app travou'])
        ->assertCreated()->assertJsonPath('request.summary', 'Alterar 08:00 → 07:55');

    $d = $day($this->employee);
    expect(TimeAdjustment::where('status', 'pendente')->count())->toBe(3)
        ->and($d['worked_minutes'])->toBe($before)
        ->and($d['requests'])->toHaveCount(3);
});

it('aplica as solicitações aprovadas no espelho preservando as batidas originais', function () use ($day) {
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Esqueci a saída'])->assertCreated();
    $this->postJson('/api/ajustes', ['type' => 'desconsideracao', 'time_entry_id' => $this->entries[3]->id, 'reason' => 'Duplicada'])->assertCreated();
    $this->postJson('/api/ajustes', ['type' => 'alteracao', 'time_entry_id' => $this->entries[0]->id, 'time' => '07:50', 'reason' => 'Horário real'])->assertCreated();

    foreach (TimeAdjustment::all() as $a) {
        $this->actingAs($this->c['rh'])->post("/ajustes/{$a->id}/aprovar")->assertSessionHasNoErrors();
    }

    $d = $day($this->employee);
    // 07:50–12:00 (250) + 13:00–17:00 (240) = 490
    expect($d['worked_minutes'])->toBe(490)
        ->and(collect($d['marks'])->where('discarded', true))->toHaveCount(2)
        ->and(TimeEntry::count())->toBe(4)
        ->and(TimeAdjustment::where('status', 'aprovado')->count())->toBe(3);
});

it('rejeita com motivo obrigatório e não aplica no cálculo', function () use ($day) {
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '18:30', 'reason' => 'Fiquei até mais tarde'])->assertCreated();
    $a = TimeAdjustment::first();

    $this->actingAs($this->c['rh'])->post("/ajustes/{$a->id}/rejeitar")->assertSessionHasErrors('note');
    $this->actingAs($this->c['rh'])->post("/ajustes/{$a->id}/rejeitar", ['note' => 'Sem registro de acesso'])->assertSessionHasNoErrors();

    expect($a->fresh()->status)->toBe('rejeitado')
        ->and($a->fresh()->decision_note)->toBe('Sem registro de acesso')
        ->and($day($this->employee)['requests'][0]['decision_note'])->toBe('Sem registro de acesso');
});

it('valida as solicitações do colaborador', function () {
    // dia futuro
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-20', 'time' => '08:00', 'reason' => 'Teste futuro'])->assertUnprocessable();
    // horário que ainda não aconteceu hoje
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-10', 'time' => '18:00', 'reason' => 'Ainda não chegou'])->assertJsonValidationErrors('time');
    // sem justificativa
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00'])->assertJsonValidationErrors('reason');
    // não pode escolher outro funcionário
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'employee_id' => 999, 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Outro func.'])->assertJsonValidationErrors('employee_id');
    // segunda solicitação para a mesma batida
    $this->postJson('/api/ajustes', ['type' => 'desconsideracao', 'time_entry_id' => $this->entries[3]->id, 'reason' => 'Duplicada'])->assertCreated();
    $this->postJson('/api/ajustes', ['type' => 'alteracao', 'time_entry_id' => $this->entries[3]->id, 'time' => '13:10', 'reason' => 'Mudei de ideia'])->assertJsonValidationErrors('time_entry_id');
});

it('não deixa solicitar ajuste em batida de outro colaborador ou de outra empresa', function () {
    $other = makeCompany('Zeta');
    $foreign = app(TimeEntryService::class)->register($other['employee']);

    $this->actingAs($this->employee->user, 'sanctum')
        ->postJson('/api/ajustes', ['type' => 'desconsideracao', 'time_entry_id' => $foreign->id, 'reason' => 'Tentativa'])
        ->assertJsonValidationErrors('time_entry_id');
});

it('gestor decide só sobre as equipes que gerencia', function () {
    $gestor = User::create(['company_id' => $this->c['company']->id, 'role' => 'gestor', 'name' => 'Gestor', 'email' => 'gestor@teste.test', 'password' => 'password']);
    $outro = User::create(['company_id' => $this->c['company']->id, 'role' => 'gestor', 'name' => 'Outro Gestor', 'email' => 'outro@teste.test', 'password' => 'password']);
    $team = Team::create(['company_id' => $this->c['company']->id, 'name' => 'Loja', 'manager_user_id' => $gestor->id]);
    $this->employee->update(['team_id' => $team->id]);

    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Esqueci a saída'])->assertCreated();
    $a = TimeAdjustment::first();

    $this->actingAs($outro)->post("/ajustes/{$a->id}/aprovar")->assertForbidden();
    $this->actingAs($outro)->get('/ajustes')->assertInertia(fn ($p) => $p->has('adjustments.data', 0));

    $this->actingAs($gestor)->get('/ajustes')->assertInertia(fn ($p) => $p->has('adjustments.data', 1)->where('pending_adjustments', 1));
    $this->actingAs($gestor)->post("/ajustes/{$a->id}/aprovar")->assertSessionHasNoErrors();
    expect($a->fresh()->status)->toBe('aprovado');
});

it('não permite decidir duas vezes', function () {
    $this->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Esqueci a saída'])->assertCreated();
    $a = TimeAdjustment::first();

    $this->actingAs($this->c['rh'])->post("/ajustes/{$a->id}/aprovar")->assertSessionHasNoErrors();
    $this->actingAs($this->c['rh'])->post("/ajustes/{$a->id}/rejeitar", ['note' => 'Tarde demais'])->assertSessionHasErrors('status');
});

it('baixa o PDF do próprio espelho pela PWA', function () {
    $this->postJson('/api/espelho/pdf', ['month' => '2026-11'])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
