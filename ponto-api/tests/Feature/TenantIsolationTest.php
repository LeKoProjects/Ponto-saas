<?php

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Tenant;
use Faker\Factory;

/* Sprint 1 — "login funciona para 2 empresas sem ver dados uma da outra". */

beforeEach(function () {
    $this->a = makeCompany('Alfa');
    $this->b = makeCompany('Beta');
});

it('lista apenas os funcionários da própria empresa', function () {
    $this->actingAs($this->a['rh'])
        ->get('/funcionarios')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Employees/Index')
            ->has('employees.data', 1)
            ->where('employees.data.0.name', 'Funcionário Alfa'));
});

it('não permite abrir funcionário de outra empresa pela URL', function () {
    $this->actingAs($this->a['rh'])
        ->get('/funcionarios/'.$this->b['employee']->id)
        ->assertNotFound();

    $this->actingAs($this->a['rh'])
        ->post('/funcionarios/'.$this->b['employee']->id, ['name' => 'Invasor'])
        ->assertNotFound();
});

it('aplica o escopo global de empresa nas consultas Eloquent', function () {
    app(Tenant::class)->set($this->a['company']->id);

    expect(Employee::count())->toBe(1)
        ->and(Employee::first()->company_id)->toBe($this->a['company']->id);
});

it('não expõe comprovante de outro funcionário pela API', function () {
    $entryB = app(TimeEntryService::class)->register($this->b['employee']);

    $this->actingAs($this->a['employee']->user, 'sanctum')
        ->postJson('/api/comprovante/'.$entryB->id)
        ->assertNotFound();
});

it('não deixa RH validar jornada de outra empresa ao cadastrar funcionário', function () {
    $this->actingAs($this->a['rh'])
        ->post('/funcionarios', [
            'name' => 'Novo', 'email' => 'novo@teste.test', 'password' => 'password123', 'role' => 'funcionario',
            'cpf' => Factory::create('pt_BR')->cpf(false), 'admission_date' => '2026-01-01',
            'work_schedule_id' => $this->b['schedule']->id,
        ])
        ->assertSessionHasErrors('work_schedule_id');
});

it('mantém o NSR independente por empresa', function () {
    $service = app(TimeEntryService::class);
    $service->register($this->a['employee']);
    $service->register($this->a['employee']);
    $b1 = $service->register($this->b['employee']);

    expect($b1->nsr)->toBe(1)
        ->and(TimeEntry::forCompany($this->a['company']->id)->max('nsr'))->toBe(2);
});

it('bloqueia o painel para empresa bloqueada', function () {
    $this->a['company']->update(['status' => 'blocked']);

    $this->actingAs($this->a['rh'])->get('/')->assertRedirect('/login');
});

it('restringe perfis: funcionário não acessa o painel e RH não acessa o admin SaaS', function () {
    $this->actingAs($this->a['employee']->user)->get('/')->assertForbidden();
    $this->actingAs($this->a['rh'])->get('/admin/empresas')->assertForbidden();
});
