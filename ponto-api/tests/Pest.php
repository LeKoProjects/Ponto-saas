<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Faker\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Cria uma empresa completa: dono, RH, jornada comercial e um funcionário.
 *
 * @return array{company: Company, owner: User, rh: User, schedule: WorkSchedule, employee: Employee}
 */
function makeCompany(string $name = 'Empresa Teste'): array
{
    static $seq = 0;
    $seq++;
    $faker = Factory::create('pt_BR');

    $company = Company::create([
        'cnpj' => str_pad((string) (11222333000100 + $seq), 14, '0', STR_PAD_LEFT),
        'legal_name' => $name.' Ltda',
        'trade_name' => $name,
        'plan' => 'profissional',
        'status' => 'active',
    ]);

    $owner = User::create(['company_id' => $company->id, 'role' => 'owner', 'name' => "Dono {$name}", 'email' => "dono{$seq}@teste.test", 'password' => 'password']);
    $rh = User::create(['company_id' => $company->id, 'role' => 'rh', 'name' => "RH {$name}", 'email' => "rh{$seq}@teste.test", 'password' => 'password']);

    $schedule = WorkSchedule::create([
        'company_id' => $company->id, 'name' => 'Comercial', 'start_time' => '08:00', 'end_time' => '17:00',
        'daily_minutes' => 480, 'break_minutes' => 60, 'weekdays' => [1, 2, 3, 4, 5], 'tolerance_minutes' => 10,
    ]);

    $user = User::create(['company_id' => $company->id, 'role' => 'funcionario', 'name' => "Funcionário {$name}", 'email' => "func{$seq}@teste.test", 'password' => 'password']);
    $employee = Employee::create([
        'company_id' => $company->id, 'user_id' => $user->id, 'cpf' => $faker->cpf(false),
        'admission_date' => '2025-01-01', 'work_schedule_id' => $schedule->id,
    ]);

    return compact('company', 'owner', 'rh', 'schedule', 'employee');
}
