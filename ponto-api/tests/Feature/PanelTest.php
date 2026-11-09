<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\TimeAdjustment;
use App\Models\User;
use App\Services\MonthlyReportService;
use App\Services\TimeEntryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Delta');
});

it('cadastra funcionário com jornada e grava auditoria', function () {
    $this->actingAs($this->c['rh'])
        ->post('/funcionarios', [
            'name' => 'Maria Teste', 'email' => 'maria@teste.test', 'password' => 'password123', 'role' => 'funcionario',
            'cpf' => '529.982.247-25', 'registration' => '0099', 'admission_date' => '2026-01-10',
            'work_schedule_id' => $this->c['schedule']->id,
        ])
        ->assertRedirect('/funcionarios');

    $this->assertDatabaseHas('employees', ['cpf' => '52998224725', 'company_id' => $this->c['company']->id]);
    expect(AuditLog::where('action', 'employee.created')->exists())->toBeTrue();
});

it('rejeita CPF inválido', function () {
    $this->actingAs($this->c['rh'])
        ->post('/funcionarios', ['name' => 'X', 'email' => 'x@teste.test', 'password' => 'password123', 'role' => 'funcionario', 'cpf' => '111.111.111-11', 'admission_date' => '2026-01-01', 'work_schedule_id' => $this->c['schedule']->id])
        ->assertSessionHasErrors('cpf');
});

it('exibe painel, espelho e exporta PDF e CSV', function () {
    Carbon::setTestNow('2026-11-09 08:00:00');
    app(TimeEntryService::class)->register($this->c['employee']);
    Carbon::setTestNow('2026-11-09 18:00:00');

    $this->actingAs($this->c['rh'])->get('/')->assertOk();
    $this->actingAs($this->c['rh'])->get('/espelho?month=2026-11')->assertOk();
    $this->actingAs($this->c['rh'])->get('/espelho/pdf?month=2026-11&employee_id='.$this->c['employee']->id)
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $csv = $this->actingAs($this->c['rh'])->get('/espelho/csv?month=2026-11')->assertOk()->streamedContent();
    expect($csv)->toContain('Funcionário Delta')->toContain('2026-11-09');
    Carbon::setTestNow();
});

it('aplica tratamento sem alterar a marcação original', function () {
    $service = app(TimeEntryService::class);
    foreach (['08:00', '12:00', '13:00', '13:05'] as $t) {
        Carbon::setTestNow("2026-11-09 {$t}:00");
        $entries[] = $service->register($this->c['employee']);
    }
    Carbon::setTestNow('2026-11-09 18:00:00');

    // Desconsidera a marcação duplicada das 13:05 e inclui a saída esquecida às 17:00.
    $this->actingAs($this->c['rh'])->post('/ajustes', ['employee_id' => $this->c['employee']->id, 'type' => 'desconsideracao', 'time_entry_id' => $entries[3]->id, 'date' => '2026-11-09', 'reason' => 'Marcação em duplicidade'])->assertSessionHasNoErrors();
    $this->actingAs($this->c['rh'])->post('/ajustes', ['employee_id' => $this->c['employee']->id, 'type' => 'inclusao', 'date' => '2026-11-09', 'time' => '17:00', 'reason' => 'Esqueceu de registrar a saída'])->assertSessionHasNoErrors();

    $day = collect(app(MonthlyReportService::class)->forEmployee($this->c['employee'], 2026, 11)['days'])->firstWhere('date', '2026-11-09');

    expect($day['worked_minutes'])->toBe(480)
        ->and(collect($day['marks'])->where('discarded', true))->toHaveCount(1)
        ->and($this->c['employee']->timeEntries()->count())->toBe(4)
        ->and(TimeAdjustment::count())->toBe(2);
    Carbon::setTestNow();
});

it('permite ao admin SaaS cadastrar e bloquear empresa', function () {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin@teste.test', 'password' => 'password', 'role' => 'super_admin']);

    $this->actingAs($admin)->post('/admin/empresas', [
        'cnpj' => '11.444.777/0001-61', 'legal_name' => 'Nova Empresa Ltda', 'plan' => 'essencial', 'status' => 'active',
        'owner_name' => 'Dono Novo', 'owner_email' => 'dono.novo@teste.test', 'owner_password' => 'password123',
    ])->assertSessionHasNoErrors();

    $company = Company::where('cnpj', '11444777000161')->firstOrFail();
    $this->actingAs($admin)->post("/admin/empresas/{$company->id}", [...$company->only(['cnpj', 'legal_name', 'plan']), 'status' => 'blocked'])->assertSessionHasNoErrors();

    expect($company->fresh()->status)->toBe('blocked');
});
