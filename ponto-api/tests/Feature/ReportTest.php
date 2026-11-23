<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\ReportService;
use App\Services\TimeEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * Relatórios: intervalo de almoço, batidas fora do local ("de casa"),
 * batidas a conferir e relatórios gerais — geral e individual.
 * Jornada de teste: 08:00–17:00, 8 h, intervalo de 1 h, seg–sex, tolerância 10 min.
 */

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Phi');
    $this->emp = $this->c['employee'];
    $this->svc = app(TimeEntryService::class);
    $this->reports = app(ReportService::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Registra as batidas de um dia: ['08:00', '12:00', ...] (opcional: coordenadas por batida). */
function punchDay($test, string $date, array $times, array $coords = [], $employee = null): void
{
    foreach ($times as $i => $t) {
        Carbon::setTestNow("{$date} {$t}:00");
        $test->svc->register($employee ?? $test->emp, $coords[$i] ?? []);
    }
    Carbon::setTestNow('2026-11-21 10:00:00'); // "hoje" = sábado depois da semana de teste
}

function runReport($test, string $type, ?int $employeeId = null, $viewer = null): array
{
    return $test->reports->build($type, $test->c['company'], $viewer ?? $test->c['rh'], CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-20'), null, $employeeId);
}

function cellText(array $row, string $key): string
{
    return ReportService::text($row['cells'][$key]);
}

it('classifica o intervalo de almoço pela CLT e pela jornada', function () {
    punchDay($this, '2026-11-16', ['08:00', '12:00', '13:00', '17:00']); // 1 h: correto
    punchDay($this, '2026-11-17', ['08:00', '12:00', '12:30', '17:30']); // 30 min: curto (faltou 30)
    punchDay($this, '2026-11-18', ['08:00', '16:00']);                   // 8 h direto: sem intervalo
    punchDay($this, '2026-11-19', ['08:00', '11:00', '13:30', '18:30']); // 2 h 30: acima de 2 h
    punchDay($this, '2026-11-20', ['08:00', '10:00']);                   // 2 h: jornada curta, não se aplica

    $r = runReport($this, 'intervalo', $this->emp->id);
    $status = collect($r['rows'])->mapWithKeys(fn ($row) => [cellText($row, 'dia') => cellText($row, 'situacao')]);

    expect($r['mode'])->toBe('individual')
        ->and($status->all())->toBe([
            '16/11 Seg' => 'Correto',
            '17/11 Ter' => 'Intervalo curto',
            '18/11 Qua' => 'Sem intervalo',
            '19/11 Qui' => 'Acima de 2 h',
        ])
        ->and(cellText($r['rows'][1], 'intervalo'))->toBe('00:30')
        ->and(cellText($r['rows'][1], 'faltou'))->toBe('00:30')
        ->and(cellText($r['rows'][2], 'faltou'))->toBe('01:00');

    // Geral: 1 linha por colaborador, com o tempo suprimido (30 min + 1 h).
    $g = runReport($this, 'intervalo');
    $mine = collect($g['rows'])->firstWhere('employee_id', $this->emp->id);
    expect($g['mode'])->toBe('geral')
        ->and(cellText($mine, 'curto'))->toBe('1')
        ->and(cellText($mine, 'sem'))->toBe('1')
        ->and(cellText($mine, 'suprimido'))->toBe('01:30');
});

it('aponta quem bateu fora dos locais da empresa e o lugar repetido (de casa)', function () {
    WorkLocation::create(['company_id' => $this->c['company']->id, 'name' => 'Sede', 'latitude' => -12.9714, 'longitude' => -38.5124, 'radius_m' => 150]);
    $sede = ['latitude' => -12.9715, 'longitude' => -38.5125];
    $casa = ['latitude' => -12.9900, 'longitude' => -38.4600]; // ~6 km

    punchDay($this, '2026-11-16', ['08:00', '12:00', '13:00', '17:00'], [$sede, $sede, $sede, $sede]);
    punchDay($this, '2026-11-17', ['08:00', '12:00', '13:00', '17:00'], [$casa, $casa, $casa, $casa]);
    punchDay($this, '2026-11-18', ['08:00', '12:00'], [$casa, $sede]);

    $r = runReport($this, 'local', $this->emp->id);
    $fora = collect($r['rows'])->filter(fn ($row) => cellText($row, 'situacao') === 'Fora do local');

    expect(count($r['rows']))->toBe(10)
        ->and($fora)->toHaveCount(5)
        ->and(cellText($fora->first(), 'local'))->toBe('Sede')
        ->and(cellText($fora->first(), 'distancia'))->toEndWith('km')
        ->and(cellText($fora->first(), 'repeticoes'))->toBe('5') // mesmo lugar 5 vezes
        ->and($fora->first()['cells']['mapa']['href'])->toContain('google.com/maps');

    $g = runReport($this, 'local');
    $mine = collect($g['rows'])->firstWhere('employee_id', $this->emp->id);
    expect($g['rows'][0]['employee_id'])->toBe($this->emp->id) // quem tem batida fora vem primeiro
        ->and(cellText($mine, 'fora'))->toBe('5')
        ->and(cellText($mine, 'percentual'))->toBe('50%')
        ->and(cellText($mine, 'mesmo_lugar'))->toBe('5 batidas');
});

it('sem locais cadastrados, avisa o RH em vez de acusar alguém', function () {
    punchDay($this, '2026-11-16', ['08:00', '17:00'], [['latitude' => -12.99, 'longitude' => -38.46], ['latitude' => -12.99, 'longitude' => -38.46]]);

    $r = runReport($this, 'local', $this->emp->id);
    expect($r['notes'][0])->toContain('Nenhum local cadastrado')
        ->and(cellText($r['rows'][0], 'situacao'))->toBe('Sem local cadastrado');
});

it('lista as batidas a conferir: falta, ímpar, duplicada, +2 h extras e interjornada', function () {
    punchDay($this, '2026-11-16', ['08:00', '12:00', '13:00', '17:00']);          // ok
    punchDay($this, '2026-11-17', ['08:00', '08:02', '12:00', '13:00', '17:00']); // ímpar + duplicada
    punchDay($this, '2026-11-18', ['08:00', '12:00', '13:00', '20:00']);          // 11 h trab. = 3 h extras
    punchDay($this, '2026-11-19', ['06:00', '12:00', '13:00', '15:00']);          // descanso 20:00→06:00 = 10 h
    // 20/11 sem batidas: falta

    $r = runReport($this, 'inconsistencias', $this->emp->id);
    $issues = collect($r['rows'])->mapWithKeys(fn ($row) => [cellText($row, 'dia') => cellText($row, 'problemas')]);

    expect($issues->has('16/11 Seg'))->toBeFalse()
        ->and($issues['17/11 Ter'])->toContain('Marcações ímpares')->toContain('Batida duplicada')
        ->and($issues['18/11 Qua'])->toContain('Mais de 2 h extras')
        ->and($issues['19/11 Qui'])->toContain('Interjornada < 11 h')
        ->and($issues['20/11 Sex'])->toContain('Falta');
});

it('resume horas, atrasos, extras e ajustes no geral e no individual', function () {
    punchDay($this, '2026-11-16', ['08:30', '12:00', '13:00', '17:00']); // 30 min de atraso
    punchDay($this, '2026-11-17', ['08:00', '12:00', '13:00', '19:00']); // 2 h extras
    $this->withToken(apiToken($this->emp->user))->postJson('/api/ajustes', ['type' => 'inclusao', 'date' => '2026-11-18', 'time' => '08:00', 'reason' => 'Esqueci de bater'])->assertCreated();

    $resumo = collect(runReport($this, 'resumo')['rows'])->firstWhere('employee_id', $this->emp->id);
    expect(cellText($resumo, 'he50'))->toBe('02:00')
        ->and(cellText($resumo, 'atraso'))->toBe('00:30')
        ->and(cellText($resumo, 'faltas'))->toBe('3');

    $atrasos = runReport($this, 'atrasos', $this->emp->id);
    expect(cellText($atrasos['rows'][0], 'atraso'))->toBe('00:30')
        ->and(cellText($atrasos['rows'][0], 'entrada'))->toBe('08:30');

    $extras = runReport($this, 'extras');
    expect($extras['rows'][0]['employee_id'])->toBe($this->emp->id)
        ->and(cellText($extras['rows'][0], 'total'))->toBe('02:00');

    $ajustes = runReport($this, 'ajustes', $this->emp->id);
    expect(cellText($ajustes['rows'][0], 'situacao'))->toBe('Pendente');
});

it('gestor só vê a própria equipe e outra empresa não aparece', function () {
    $gestor = User::create(['company_id' => $this->c['company']->id, 'role' => 'gestor', 'name' => 'Gestora', 'email' => 'gestora@phi.test', 'password' => 'password']);
    $team = Team::create(['company_id' => $this->c['company']->id, 'name' => 'Loja', 'manager_user_id' => $gestor->id]);
    $other = makeCompany('Chi');

    expect(runReport($this, 'resumo', null, $gestor)['rows'])->toBe([]);

    $this->emp->update(['team_id' => $team->id]);
    $rows = runReport($this, 'resumo', null, $gestor)['rows'];
    expect($rows)->toHaveCount(1)->and($rows[0]['employee_id'])->toBe($this->emp->id);

    // RH da outra empresa: nem pelo id do colaborador.
    $this->actingAs($other['rh'])->get('/relatorios?type=resumo&employee_id='.$this->emp->id)
        ->assertInertia(fn ($p) => $p->where('report.rows.total', 0));
});

it('abre a tela, pagina de 20 em 20 e exporta CSV e PDF com auditoria', function () {
    punchDay($this, '2026-11-17', ['08:00', '12:00', '12:30', '17:30']);

    $this->actingAs($this->c['rh'])->get('/relatorios?type=intervalo&from=2026-11-16&to=2026-11-20')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reports/Index')
            ->where('filters.type', 'intervalo')
            ->where('report.mode', 'geral')
            ->has('report.columns')
            ->where('report.rows.per_page', 20));

    $csv = $this->actingAs($this->c['rh'])->get('/relatorios/exportar/csv?type=intervalo&from=2026-11-16&to=2026-11-20&employee_id='.$this->emp->id);
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Intervalo curto')->toContain('Intervalo de almoço');

    $this->actingAs($this->c['rh'])->get('/relatorios/exportar/pdf?type=local&from=2026-11-16&to=2026-11-20')
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    expect(AuditLog::where('action', 'report.exported')->count())->toBe(2);
    $this->actingAs($this->emp->user)->get('/relatorios')->assertForbidden();
});
