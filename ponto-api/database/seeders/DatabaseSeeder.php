<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\TimeEntryService;
use App\Support\Tenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração. Senha de todos os usuários: "password".
 *   admin@ponto.test       — Admin do SaaS
 *   dono@clinica.test      — Dono da Clínica Bem Estar
 *   rh@clinica.test        — RH da Clínica
 *   ana@clinica.test       — Funcionária (use na PWA)
 *   dono@restaurante.test  — Dono do Restaurante Sabor Baiano
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        config(['mail.default' => 'log', 'queue.default' => 'sync']);
        $this->call(HolidaySeeder::class);

        User::firstOrCreate(['email' => 'admin@ponto.test'], ['name' => 'Admin SaaS', 'role' => 'super_admin', 'password' => 'password']);

        $this->seedCompany('12345678000195', 'Clínica Bem Estar Ltda', 'Clínica Bem Estar', 'profissional', 'clinica', [
            ['Ana Souza', 'ana'], ['Bruno Lima', 'bruno'], ['Carla Menezes', 'carla'], ['Diego Santos', 'diego'], ['Elaine Rocha', 'elaine'],
        ]);

        $this->seedCompany('98765432000110', 'Sabor Baiano Restaurante ME', 'Restaurante Sabor Baiano', 'essencial', 'restaurante', [
            ['Fábio Nascimento', 'fabio'], ['Gisele Araújo', 'gisele'], ['Hugo Pereira', 'hugo'],
        ]);
    }

    private function seedCompany(string $cnpj, string $legal, string $trade, string $plan, string $slug, array $people): void
    {
        if (Company::where('cnpj', $cnpj)->exists()) {
            return;
        }

        $faker = Faker::create('pt_BR');
        $company = Company::create(['cnpj' => $cnpj, 'legal_name' => $legal, 'trade_name' => $trade, 'plan' => $plan, 'status' => 'active']);

        app(Tenant::class)->run($company->id, function () use ($company, $slug, $people, $faker) {
            $owner = User::create(['company_id' => $company->id, 'role' => 'owner', 'name' => "Dono {$company->trade_name}", 'email' => "dono@{$slug}.test", 'password' => 'password']);
            User::create(['company_id' => $company->id, 'role' => 'rh', 'name' => 'Recursos Humanos', 'email' => "rh@{$slug}.test", 'password' => 'password']);

            $comercial = WorkSchedule::create(['name' => 'Comercial 8h (seg–sex)', 'start_time' => '08:00', 'end_time' => '17:00', 'daily_minutes' => 480, 'break_minutes' => 60, 'weekdays' => [1, 2, 3, 4, 5], 'tolerance_minutes' => 10]);
            WorkSchedule::create(['name' => 'Meio período 4h', 'start_time' => '13:00', 'end_time' => '17:00', 'daily_minutes' => 240, 'break_minutes' => 0, 'weekdays' => [1, 2, 3, 4, 5, 6], 'tolerance_minutes' => 5]);
            $team = Team::create(['name' => 'Atendimento', 'manager_user_id' => $owner->id]);

            $employees = [];
            foreach ($people as $i => [$name, $login]) {
                $user = User::create(['company_id' => $company->id, 'role' => 'funcionario', 'name' => $name, 'email' => "{$login}@{$slug}.test", 'password' => 'password']);
                $employees[] = Employee::create([
                    'user_id' => $user->id,
                    'cpf' => $faker->cpf(false),
                    'registration' => sprintf('%04d', $i + 1),
                    'admission_date' => now()->subYear()->toDateString(),
                    'work_schedule_id' => $comercial->id,
                    'team_id' => $team->id,
                ]);
            }

            $this->seedEntries($company, $employees);
        });
    }

    /** Marcações do mês corrente até ontem, com variações (atrasos, extras, faltas). */
    private function seedEntries(Company $company, array $employees): void
    {
        $service = app(TimeEntryService::class);
        $start = CarbonImmutable::now()->startOfMonth();
        $end = CarbonImmutable::yesterday();
        if ($end->lt($start)) {
            return;
        }

        $holidays = Holiday::applicableTo($company->id)->pluck('date')->map->toDateString()->all();
        mt_srand($company->id);

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $day = CarbonImmutable::instance($day);
            if ($day->isWeekend() || in_array($day->toDateString(), $holidays, true)) {
                continue;
            }

            $marksByEmployee = [];
            foreach ($employees as $e) {
                if (mt_rand(1, 100) <= 4) {
                    continue; // falta
                }
                $in = $day->setTime(8, 0)->addMinutes(mt_rand(-8, mt_rand(1, 100) <= 15 ? 35 : 8));
                $lunch = $day->setTime(12, 0)->addMinutes(mt_rand(-5, 10));
                $back = $lunch->addMinutes(60 + mt_rand(-3, 5));
                $out = $day->setTime(17, 0)->addMinutes(mt_rand(-5, mt_rand(1, 100) <= 20 ? 90 : 10));
                foreach ([$in, $lunch, $back, $out] as $m) {
                    $marksByEmployee[] = [$m->addSeconds(mt_rand(0, 59)), $e];
                }
            }

            // NSR é por empresa e cronológico: registra na ordem do horário.
            usort($marksByEmployee, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($marksByEmployee as [$at, $e]) {
                Carbon::setTestNow($at);
                $service->register($e, ['ip' => '127.0.0.1', 'device' => 'seeder']);
            }
        }

        Carbon::setTestNow();
    }
}
