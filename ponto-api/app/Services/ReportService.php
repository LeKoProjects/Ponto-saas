<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\TimeAdjustment;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relatórios do RH. Cada relatório tem visão GERAL (uma linha por colaborador)
 * e INDIVIDUAL (dia a dia ou batida a batida de um colaborador).
 *
 * Os números vêm do mesmo cálculo do espelho (MonthlyReportService), com os
 * ajustes aprovados já aplicados. Saída padronizada para tela, CSV e PDF:
 *   columns: [{key, label, align}]
 *   rows:    [{cells: {key: string|{text, tone, badge, href, badges}}, employee_id}]
 *   summary: [{label, value, tone}]
 *   notes:   [string]
 */
class ReportService
{
    public const MAX_DAYS = 93;

    /** Folga do GPS ao classificar dentro/fora do local (o relatório não sabe a precisão de cada batida). */
    public const GPS_MARGIN_M = 50;

    /** Duas marcações com menos que isso entre elas = batida duplicada. */
    public const DUPLICATE_MINUTES = 5;

    public const TYPES = [
        'intervalo' => ['label' => 'Intervalo de almoço', 'icon' => 'bi-cup-hot', 'description' => 'Se o intervalo foi tirado e por quanto tempo (CLT art. 71: 1 h a 2 h para jornada acima de 6 h).'],
        'local' => ['label' => 'Batidas fora do local', 'icon' => 'bi-geo-alt', 'description' => 'Quem bateu o ponto fora dos locais da empresa (ex.: de casa), com a distância e o mapa.'],
        'inconsistencias' => ['label' => 'Batidas a conferir', 'icon' => 'bi-exclamation-triangle', 'description' => 'Faltas, marcações ímpares, batidas duplicadas, mais de 2 h extras, interjornada menor que 11 h e outros pontos de atenção.'],
        'resumo' => ['label' => 'Resumo de horas', 'icon' => 'bi-clock-history', 'description' => 'Previsto, trabalhado, extras, atrasos, faltas, noturno e saldo.'],
        'atrasos' => ['label' => 'Atrasos e faltas', 'icon' => 'bi-alarm', 'description' => 'Atrasos na entrada, faltas e horas faltantes, com a assiduidade de cada um.'],
        'extras' => ['label' => 'Horas extras e noturno', 'icon' => 'bi-graph-up-arrow', 'description' => 'Horas extras 50% e 100% e adicional noturno, de quem mais fez para quem menos fez.'],
        'ajustes' => ['label' => 'Ajustes de ponto', 'icon' => 'bi-pencil-square', 'description' => 'Pedidos de inclusão, exclusão e alteração de batidas e como foram decididos.'],
    ];

    public function __construct(private MonthlyReportService $timesheet) {}

    /**
     * @return array{type: string, mode: string, title: string, subtitle: string, columns: array, rows: array, summary: array, notes: array}
     */
    public function build(string $type, Company $company, User $viewer, CarbonImmutable $from, CarbonImmutable $to, ?int $teamId = null, ?int $employeeId = null): array
    {
        $employees = $this->employees($company, $viewer, $teamId, $employeeId, $to);
        $individual = $employeeId !== null;

        $report = match ($type) {
            'ajustes' => $this->ajustes($company, $employees, $from, $to, $individual),
            default => $this->{$type}($this->dataset($employees, $from, $to, $company), $individual, $company),
        };

        $who = $individual
            ? ($employees->first()?->user->name ?? 'Colaborador')
            : 'Todos os colaboradores'.($teamId ? ' da equipe '.($employees->first()?->team?->name ?? '') : '');

        return [
            'type' => $type,
            'mode' => $individual ? 'individual' : 'geral',
            'title' => self::TYPES[$type]['label'],
            'subtitle' => $who.' · '.$from->format('d/m/Y').' a '.$to->format('d/m/Y'),
            ...$report,
        ];
    }

    /** Colaboradores visíveis para quem pede o relatório (gestor: só as equipes que gerencia). */
    public function employees(Company $company, User $viewer, ?int $teamId, ?int $employeeId, CarbonImmutable $to): Collection
    {
        return Employee::forCompany($company->id)
            ->with(['user:id,name,email', 'team:id,name', 'workSchedule'])
            ->whereDate('admission_date', '<=', $to)
            ->when($viewer->hasRole('gestor'), fn ($q) => $q->whereHas('team', fn ($t) => $t->where('manager_user_id', $viewer->id)))
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when($employeeId, fn ($q) => $q->whereKey($employeeId))
            ->get()
            ->sortBy(fn ($e) => mb_strtolower($e->user->name))
            ->values();
    }

    // =====================================================================
    // Base de dados comum: dias calculados (com ajustes) + análise do dia
    // =====================================================================

    /** @return array<int, array{employee: Employee, days: array}> */
    private function dataset(Collection $employees, CarbonImmutable $from, CarbonImmutable $to, Company $company): array
    {
        $locations = WorkLocation::forCompany($company->id)->get()->map->toContext()->all();
        $today = CarbonImmutable::today();
        $out = [];

        foreach ($employees as $employee) {
            // Um dia antes para medir a interjornada do primeiro dia.
            $r = $this->timesheet->forEmployeeRange($employee, $from->subDay(), $to, true);
            $prevLast = null;
            $days = [];
            foreach ($r['days'] as $day) {
                $effective = $day['_effective'];
                if ($day['date'] < $from->toDateString()) {
                    $prevLast = $effective ? end($effective) : null;

                    continue;
                }
                if ($day['date'] > $today->toDateString()) {
                    break; // dias futuros não entram
                }
                $day['break'] = $this->breakAnalysis($day, $employee);
                $day['rest_minutes'] = ($prevLast && $effective) ? intdiv($effective[0]->getTimestamp() - $prevLast->getTimestamp(), 60) : null;
                $day['punches'] = $this->classifyPunches($day['_entries'], $locations);
                $days[] = $day;
                $prevLast = $effective ? end($effective) : $prevLast;
            }
            $out[] = ['employee' => $employee, 'days' => $days, 'has_locations' => (bool) $locations];
        }

        return $out;
    }

    /**
     * Intervalo do dia (CLT art. 71):
     *  - trabalhou mais de 6 h → mínimo 1 h, máximo 2 h;
     *  - de 4 h a 6 h → mínimo 15 min;
     * e comparação com o intervalo previsto na jornada.
     */
    public function breakAnalysis(array $day, Employee $employee): array
    {
        $marks = $day['_effective'];
        $worked = $day['worked_minutes'];
        $required = $worked > 360 ? 60 : ($worked > 240 ? 15 : 0);
        $scheduled = (int) $employee->workSchedule->break_minutes;
        $tolerance = (int) $employee->workSchedule->tolerance_minutes;

        $gaps = [];
        for ($i = 1; $i + 1 < count($marks); $i += 2) {
            $gaps[] = intdiv($marks[$i + 1]->getTimestamp() - $marks[$i]->getTimestamp(), 60);
        }
        $taken = $gaps ? max($gaps) : null;
        $start = count($marks) >= 4 ? $marks[1]->format('H:i') : null;
        $end = count($marks) >= 4 ? $marks[2]->format('H:i') : null;

        $base = ['taken' => $taken, 'required' => $required, 'scheduled' => $scheduled, 'start' => $start, 'end' => $end, 'missing' => 0];

        if (count($marks) < 2 || $day['incomplete'] || $day['in_progress']) {
            return $base + ['status' => 'na', 'label' => 'Não se aplica'];
        }
        if ($required === 0) {
            return $base + ['status' => 'na', 'label' => 'Jornada curta (até 4 h)'];
        }
        if ($taken === null) {
            return $required > 0
                ? ['status' => 'sem', 'label' => 'Sem intervalo', 'missing' => $required] + $base
                : $base + ['status' => 'na', 'label' => 'Jornada curta'];
        }
        if ($taken < $required) {
            return ['status' => 'curto', 'label' => 'Intervalo curto', 'missing' => $required - $taken] + $base;
        }
        if ($scheduled > 0 && $taken < $scheduled - $tolerance) {
            return ['status' => 'abaixo', 'label' => 'Abaixo do previsto', 'missing' => $scheduled - $taken] + $base;
        }
        if ($worked > 360 && $taken > 120) {
            return $base + ['status' => 'longo', 'label' => 'Acima de 2 h'];
        }

        return $base + ['status' => 'ok', 'label' => 'Correto'];
    }

    /** Cada batida com localização: local mais próximo, distância e se ficou fora do raio (+50 m de folga do GPS). */
    private function classifyPunches(array $entries, array $locations): array
    {
        return array_map(function ($e) use ($locations) {
            $p = ['time' => $e['time'], 'type_label' => $e['type_label'], 'nsr' => $e['nsr'], 'latitude' => $e['latitude'], 'longitude' => $e['longitude'], 'status' => 'sem_gps', 'location' => null, 'distance' => null];
            if ($e['latitude'] === null || $e['longitude'] === null) {
                return $p;
            }
            if (! $locations) {
                return ['status' => 'sem_local'] + $p;
            }
            $n = Geo::nearest($locations, (float) $e['latitude'], (float) $e['longitude']);
            $inside = $n['distance'] <= $n['location']['radius_m'] + self::GPS_MARGIN_M;

            return ['status' => $inside ? 'dentro' : 'fora', 'location' => $n['location']['name'], 'distance' => $n['distance']] + $p;
        }, $entries);
    }

    /** Problemas do dia para "Batidas a conferir". @return array<int, array{0: string, 1: string}> [texto, tom] */
    public function dayIssues(array $day): array
    {
        $issues = [];
        $marks = $day['_effective'];

        if ($day['absent'] && ! $day['in_progress']) {
            $issues[] = ['Falta', 'danger'];
        }
        if ($day['incomplete'] && ! $day['in_progress']) {
            $issues[] = ['Marcações ímpares', 'danger'];
        }
        for ($i = 1; $i < count($marks); $i++) {
            if (intdiv($marks[$i]->getTimestamp() - $marks[$i - 1]->getTimestamp(), 60) < self::DUPLICATE_MINUTES) {
                $issues[] = ['Batida duplicada', 'warning'];
                break;
            }
        }
        if (in_array($day['break']['status'], ['sem', 'curto'], true)) {
            $issues[] = [$day['break']['label'], 'danger'];
        }
        if ($day['is_workday'] && $day['overtime_50_minutes'] > 120) {
            $issues[] = ['Mais de 2 h extras', 'warning'];
        }
        if ($day['rest_minutes'] !== null && $day['rest_minutes'] < 660) {
            $issues[] = ['Interjornada < 11 h', 'warning'];
        }
        if (! $day['is_workday'] && $day['worked_minutes'] > 0) {
            $issues[] = [$day['is_holiday'] ? 'Trabalho em feriado' : 'Trabalho em folga', 'info'];
        }
        if (collect($day['punches'])->contains('status', 'fora')) {
            $issues[] = ['Batida fora do local', 'warning'];
        }
        if (collect($day['_requests'])->contains('status', 'pendente')) {
            $issues[] = ['Ajuste pendente', 'secondary'];
        }

        return $issues;
    }

    // =====================================================================
    // Relatórios
    // =====================================================================

    private function intervalo(array $data, bool $individual): array
    {
        $tones = ['ok' => 'success', 'curto' => 'danger', 'sem' => 'danger', 'abaixo' => 'warning', 'longo' => 'warning', 'na' => 'secondary'];
        $all = collect($data)->flatMap(fn ($d) => $d['days'])->filter(fn ($day) => $day['break']['status'] !== 'na');
        $summary = [
            ['label' => 'Dias analisados', 'value' => $all->count()],
            ['label' => 'Intervalo correto', 'value' => $all->where('break.status', 'ok')->count(), 'tone' => 'success'],
            ['label' => 'Curto ou sem intervalo', 'value' => $all->whereIn('break.status', ['curto', 'sem'])->count(), 'tone' => 'danger'],
            ['label' => 'Abaixo do previsto / acima de 2 h', 'value' => $all->whereIn('break.status', ['abaixo', 'longo'])->count(), 'tone' => 'warning'],
            ['label' => 'Tempo de intervalo suprimido', 'value' => $this->hm($all->whereIn('break.status', ['curto', 'sem'])->sum('break.missing')), 'tone' => 'danger'],
        ];
        $notes = [
            'CLT art. 71: jornada acima de 6 h exige intervalo de 1 h a 2 h; de 4 h a 6 h, 15 min. Qualquer redução do mínimo conta como intervalo suprimido, devido com 50% (§ 4º).',
            '"Abaixo do previsto": cumpriu o mínimo legal, mas tirou menos que o intervalo da jornada cadastrada (com a tolerância da jornada).',
        ];

        if ($individual) {
            $rows = collect($data[0]['days'] ?? [])->filter(fn ($d) => $d['break']['status'] !== 'na')->map(fn ($d) => ['cells' => [
                'dia' => $d['label'],
                'marcacoes' => $this->marks($d),
                'saida' => $d['break']['start'] ?? '—',
                'volta' => $d['break']['end'] ?? '—',
                'intervalo' => $d['break']['taken'] !== null ? $this->hm($d['break']['taken']) : '—',
                'previsto' => $this->hm($d['break']['scheduled']),
                'minimo' => $this->hm($d['break']['required']),
                'faltou' => $d['break']['missing'] ? $this->cell($this->hm($d['break']['missing']), 'danger') : '—',
                'situacao' => $this->badge($d['break']['label'], $tones[$d['break']['status']]),
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'marcacoes' => 'Marcações', 'saida' => 'Saída', 'volta' => 'Volta', 'intervalo' => 'Intervalo', 'previsto' => 'Previsto', 'minimo' => 'Mínimo legal', 'faltou' => 'Faltou', 'situacao' => 'Situação']), 'rows' => $rows, 'summary' => $summary, 'notes' => $notes];
        }

        $rows = collect($data)->map(function ($d) {
            $days = collect($d['days'])->filter(fn ($day) => $day['break']['status'] !== 'na');
            $bad = $days->whereIn('break.status', ['curto', 'sem'])->count();
            $taken = $days->pluck('break.taken')->filter(fn ($v) => $v !== null);

            return ['employee_id' => $d['employee']->id, 'sort' => [-$bad, -$days->whereIn('break.status', ['abaixo', 'longo'])->count()], 'cells' => [
                'colaborador' => $d['employee']->user->name,
                'equipe' => $d['employee']->team?->name ?? '—',
                'dias' => (string) $days->count(),
                'ok' => $this->cell((string) $days->where('break.status', 'ok')->count(), 'success'),
                'curto' => $this->cell((string) $days->where('break.status', 'curto')->count(), $days->where('break.status', 'curto')->count() ? 'danger' : null),
                'sem' => $this->cell((string) $days->where('break.status', 'sem')->count(), $days->where('break.status', 'sem')->count() ? 'danger' : null),
                'abaixo' => (string) $days->where('break.status', 'abaixo')->count(),
                'longo' => (string) $days->where('break.status', 'longo')->count(),
                'media' => $taken->count() ? $this->hm((int) round($taken->avg())) : '—',
                'suprimido' => $bad ? $this->cell($this->hm($days->whereIn('break.status', ['curto', 'sem'])->sum('break.missing')), 'danger') : '—',
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'dias' => 'Dias', 'ok' => 'Correto', 'curto' => 'Curto', 'sem' => 'Sem intervalo', 'abaixo' => 'Abaixo do previsto', 'longo' => 'Acima de 2 h', 'media' => 'Média', 'suprimido' => 'Suprimido']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => $notes];
    }

    private function local(array $data, bool $individual, Company $company): array
    {
        $hasLocations = (bool) ($data[0]['has_locations'] ?? WorkLocation::forCompany($company->id)->exists());
        $punches = collect($data)->flatMap(fn ($d) => collect($d['days'])->flatMap(fn ($day) => array_map(fn ($p) => $p + ['day' => $day['label'], 'employee' => $d['employee']], $day['punches'])));
        $withGps = $punches->where('status', '!=', 'sem_gps');
        $outside = $punches->where('status', 'fora');

        $summary = [
            ['label' => 'Batidas no período', 'value' => $punches->count()],
            ['label' => 'Com localização', 'value' => $withGps->count()],
            ['label' => 'Dentro do local', 'value' => $punches->where('status', 'dentro')->count(), 'tone' => 'success'],
            ['label' => 'Fora do local', 'value' => $outside->count(), 'tone' => $outside->count() ? 'danger' : 'success'],
            ['label' => 'Colaboradores com batida fora', 'value' => $outside->pluck('employee.id')->unique()->count(), 'tone' => 'warning'],
        ];
        $notes = [
            'O sistema não sabe o endereço de casa de ninguém: "fora do local" é qualquer batida além do raio dos locais cadastrados em Configurações (com '.self::GPS_MARGIN_M.' m de folga pela imprecisão do GPS).',
            '"Mesmo lugar fora" conta batidas fora num mesmo ponto (num raio de ~100 m): várias batidas no mesmo lugar fora da empresa costumam ser a casa do colaborador.',
            'Só há localização nas batidas feitas com "Exigir localização" ligado (LGPD: sem a opção, a posição não é coletada).',
        ];
        if (! $hasLocations) {
            array_unshift($notes, 'Nenhum local cadastrado: cadastre a sede e as filiais em Configurações → Locais permitidos para o relatório saber o que é "fora".');
        }

        if ($individual) {
            $clusters = $this->clusters($outside);
            $rows = $punches->filter(fn ($p) => $p['status'] !== 'sem_gps')->map(fn ($p) => ['cells' => [
                'dia' => $p['day'],
                'hora' => $p['time'],
                'tipo' => $p['type_label'],
                'local' => $p['location'] ?? '—',
                'distancia' => $p['distance'] !== null ? $this->cell(Geo::human($p['distance']), $p['status'] === 'fora' ? 'danger' : null) : '—',
                'situacao' => match ($p['status']) {
                    'dentro' => $this->badge('Dentro do local', 'success'),
                    'fora' => $this->badge('Fora do local', 'danger'),
                    default => $this->badge('Sem local cadastrado', 'secondary'),
                },
                'repeticoes' => $p['status'] === 'fora' ? (string) ($clusters[$this->clusterKey($p)] ?? 1) : '—',
                'mapa' => $this->cell(sprintf('%.5f, %.5f', $p['latitude'], $p['longitude']), null, false, $this->mapUrl($p)),
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'hora' => 'Hora', 'tipo' => 'Tipo', 'local' => 'Local mais próximo', 'distancia' => 'Distância', 'situacao' => 'Situação', 'repeticoes' => 'Mesmo lugar fora', 'mapa' => 'Coordenadas (mapa)']), 'rows' => $rows, 'summary' => $summary, 'notes' => $notes];
        }

        $rows = collect($data)->map(function ($d) use ($punches) {
            $mine = $punches->where('employee.id', $d['employee']->id);
            $out = $mine->where('status', 'fora');
            $gps = $mine->where('status', '!=', 'sem_gps');
            $top = $this->clusters($out);

            return ['employee_id' => $d['employee']->id, 'sort' => [-$out->count()], 'cells' => [
                'colaborador' => $d['employee']->user->name,
                'equipe' => $d['employee']->team?->name ?? '—',
                'batidas' => (string) $mine->count(),
                'com_gps' => (string) $gps->count(),
                'dentro' => (string) $mine->where('status', 'dentro')->count(),
                'fora' => $this->cell((string) $out->count(), $out->count() ? 'danger' : 'success'),
                'percentual' => $gps->count() ? round($out->count() / $gps->count() * 100).'%' : '—',
                'maior' => $out->count() ? Geo::human($out->max('distance')) : '—',
                'mesmo_lugar' => $top ? $this->cell(max($top).' batidas', max($top) >= 3 ? 'warning' : null) : '—',
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'batidas' => 'Batidas', 'com_gps' => 'Com localização', 'dentro' => 'Dentro', 'fora' => 'Fora', 'percentual' => '% fora', 'maior' => 'Maior distância', 'mesmo_lugar' => 'Mesmo lugar fora']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => $notes];
    }

    private function inconsistencias(array $data, bool $individual): array
    {
        $byDay = collect($data)->flatMap(fn ($d) => collect($d['days'])->map(fn ($day) => ['employee' => $d['employee'], 'day' => $day, 'issues' => $this->dayIssues($day)]));
        $withIssues = $byDay->filter(fn ($x) => $x['issues']);
        $count = fn (string $label) => $withIssues->sum(fn ($x) => collect($x['issues'])->contains(fn ($i) => $i[0] === $label) ? 1 : 0);

        $summary = [
            ['label' => 'Dias com algo a conferir', 'value' => $withIssues->count(), 'tone' => $withIssues->count() ? 'warning' : 'success'],
            ['label' => 'Faltas', 'value' => $count('Falta'), 'tone' => 'danger'],
            ['label' => 'Marcações ímpares', 'value' => $count('Marcações ímpares'), 'tone' => 'danger'],
            ['label' => 'Intervalo irregular', 'value' => $count('Intervalo curto') + $count('Sem intervalo'), 'tone' => 'danger'],
            ['label' => 'Interjornada < 11 h', 'value' => $count('Interjornada < 11 h'), 'tone' => 'warning'],
        ];
        $notes = [
            'Marcações ímpares: falta a saída (ou a entrada) de algum período; peça um ajuste ao colaborador.',
            'Batida duplicada: duas marcações com menos de '.self::DUPLICATE_MINUTES.' min entre elas.',
            'Mais de 2 h extras: acima do limite diário da CLT (art. 59). Interjornada: descanso mínimo de 11 h entre um dia e outro (art. 66).',
        ];

        if ($individual) {
            $rows = $withIssues->map(fn ($x) => ['cells' => [
                'dia' => $x['day']['label'],
                'marcacoes' => $this->marks($x['day']),
                'trabalhado' => $this->hm($x['day']['worked_minutes']),
                'problemas' => ['badges' => array_map(fn ($i) => ['text' => $i[0], 'tone' => $i[1]], $x['issues'])],
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'marcacoes' => 'Marcações', 'trabalhado' => 'Trabalhado', 'problemas' => 'O que conferir']), 'rows' => $rows, 'summary' => $summary, 'notes' => $notes];
        }

        $types = ['Falta', 'Marcações ímpares', 'Batida duplicada', 'Intervalo curto', 'Sem intervalo', 'Mais de 2 h extras', 'Interjornada < 11 h', 'Batida fora do local', 'Ajuste pendente'];
        $rows = collect($data)->map(function ($d) use ($withIssues, $types) {
            $mine = $withIssues->where('employee.id', $d['employee']->id);
            $n = fn (string $label) => $mine->sum(fn ($x) => collect($x['issues'])->contains(fn ($i) => $i[0] === $label) ? 1 : 0);
            $cells = ['colaborador' => $d['employee']->user->name, 'equipe' => $d['employee']->team?->name ?? '—', 'dias' => $this->cell((string) $mine->count(), $mine->count() ? 'warning' : 'success')];
            foreach ($types as $t) {
                $v = $n($t);
                $cells[$t] = $v ? $this->cell((string) $v, in_array($t, ['Falta', 'Marcações ímpares', 'Intervalo curto', 'Sem intervalo'], true) ? 'danger' : 'warning') : '—';
            }

            return ['employee_id' => $d['employee']->id, 'sort' => [-$mine->count()], 'cells' => $cells];
        });
        $labels = ['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'dias' => 'Dias a conferir'] + array_combine($types, ['Faltas', 'Ímpares', 'Duplicadas', 'Interv. curto', 'Sem interv.', '+2 h extras', 'Interjornada', 'Fora do local', 'Ajuste pendente']);

        return ['columns' => $this->cols($labels), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => $notes];
    }

    private function resumo(array $data, bool $individual): array
    {
        $sum = fn (string $k) => collect($data)->sum(fn ($d) => collect($d['days'])->sum($k));
        $summary = [
            ['label' => 'Previsto', 'value' => $this->hm($sum('expected_minutes'))],
            ['label' => 'Trabalhado', 'value' => $this->hm($sum('worked_minutes'))],
            ['label' => 'Horas extras', 'value' => $this->hm($sum('overtime_50_minutes') + $sum('overtime_100_minutes')), 'tone' => 'success'],
            ['label' => 'Atrasos + faltantes', 'value' => $this->hm($sum('late_minutes') + $sum('missing_minutes')), 'tone' => 'danger'],
            ['label' => 'Faltas', 'value' => collect($data)->sum(fn ($d) => collect($d['days'])->where('absent', true)->count()), 'tone' => 'danger'],
        ];

        if ($individual) {
            $rows = collect($data[0]['days'] ?? [])->map(fn ($d) => ['cells' => [
                'dia' => $d['label'],
                'marcacoes' => $this->marks($d),
                'previsto' => $this->hm($d['expected_minutes']),
                'trabalhado' => $this->hm($d['worked_minutes']),
                'atraso' => $d['late_minutes'] ? $this->cell($this->hm($d['late_minutes']), 'danger') : '—',
                'he50' => $d['overtime_50_minutes'] ? $this->hm($d['overtime_50_minutes']) : '—',
                'he100' => $d['overtime_100_minutes'] ? $this->hm($d['overtime_100_minutes']) : '—',
                'faltante' => $d['missing_minutes'] ? $this->cell($this->hm($d['missing_minutes']), 'danger') : '—',
                'noturno' => $d['night_minutes'] ? $this->hm($d['night_minutes']) : '—',
                'obs' => $d['holiday'] ? $this->badge($d['holiday'], 'info') : ($d['absent'] && ! $d['in_progress'] ? $this->badge('Falta', 'danger') : ($d['incomplete'] && ! $d['in_progress'] ? $this->badge('Incompleto', 'warning') : '')),
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'marcacoes' => 'Marcações', 'previsto' => 'Previsto', 'trabalhado' => 'Trabalhado', 'atraso' => 'Atraso', 'he50' => 'HE 50%', 'he100' => 'HE 100%', 'faltante' => 'Faltante', 'noturno' => 'Noturno', 'obs' => 'Obs.']), 'rows' => $rows, 'summary' => $summary, 'notes' => []];
        }

        $rows = collect($data)->map(function ($d) {
            $days = collect($d['days']);
            $balance = $days->sum('overtime_50_minutes') + $days->sum('overtime_100_minutes') - $days->sum('missing_minutes');

            return ['employee_id' => $d['employee']->id, 'sort' => [], 'cells' => [
                'colaborador' => $d['employee']->user->name,
                'equipe' => $d['employee']->team?->name ?? '—',
                'dias' => (string) $days->filter(fn ($x) => $x['worked_minutes'] > 0)->count(),
                'faltas' => $this->cell((string) $days->where('absent', true)->where('in_progress', false)->count(), $days->where('absent', true)->where('in_progress', false)->count() ? 'danger' : null),
                'previsto' => $this->hm($days->sum('expected_minutes')),
                'trabalhado' => $this->hm($days->sum('worked_minutes')),
                'he50' => $this->hm($days->sum('overtime_50_minutes')),
                'he100' => $this->hm($days->sum('overtime_100_minutes')),
                'atraso' => $this->hm($days->sum('late_minutes')),
                'faltante' => $this->hm($days->sum('missing_minutes')),
                'noturno' => $this->hm($days->sum('night_minutes')),
                'saldo' => $this->cell($this->hm($balance), $balance < 0 ? 'danger' : ($balance > 0 ? 'success' : null)),
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'dias' => 'Dias trab.', 'faltas' => 'Faltas', 'previsto' => 'Previsto', 'trabalhado' => 'Trabalhado', 'he50' => 'HE 50%', 'he100' => 'HE 100%', 'atraso' => 'Atraso', 'faltante' => 'Faltante', 'noturno' => 'Noturno', 'saldo' => 'Saldo']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => ['Saldo = horas extras − horas faltantes no período (sem banco de horas).']];
    }

    private function atrasos(array $data, bool $individual): array
    {
        $all = collect($data)->flatMap(fn ($d) => $d['days']);
        $summary = [
            ['label' => 'Dias com atraso', 'value' => $all->where('late_minutes', '>', 0)->count(), 'tone' => 'warning'],
            ['label' => 'Total de atraso', 'value' => $this->hm($all->sum('late_minutes')), 'tone' => 'warning'],
            ['label' => 'Faltas', 'value' => $all->where('absent', true)->where('in_progress', false)->count(), 'tone' => 'danger'],
            ['label' => 'Horas faltantes', 'value' => $this->hm($all->sum('missing_minutes')), 'tone' => 'danger'],
        ];
        $notes = ['Atraso: primeira batida depois do início da jornada + tolerância. Assiduidade: dias úteis com batida ÷ dias úteis do período (até hoje).'];

        if ($individual) {
            $emp = $data[0]['employee'] ?? null;
            $rows = collect($data[0]['days'] ?? [])->filter(fn ($d) => $d['late_minutes'] > 0 || $d['missing_minutes'] > 0 || ($d['absent'] && ! $d['in_progress']))->map(fn ($d) => ['cells' => [
                'dia' => $d['label'],
                'inicio' => $emp ? substr($emp->workSchedule->start_time, 0, 5) : '—',
                'entrada' => $d['_effective'] ? $d['_effective'][0]->format('H:i') : '—',
                'atraso' => $d['late_minutes'] ? $this->cell($this->hm($d['late_minutes']), 'danger') : '—',
                'faltante' => $d['missing_minutes'] ? $this->hm($d['missing_minutes']) : '—',
                'situacao' => $d['absent'] ? $this->badge('Falta', 'danger') : ($d['late_minutes'] ? $this->badge('Atraso', 'warning') : $this->badge('Saiu antes / faltou tempo', 'warning')),
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'inicio' => 'Início previsto', 'entrada' => '1ª batida', 'atraso' => 'Atraso', 'faltante' => 'Faltante', 'situacao' => 'Situação']), 'rows' => $rows, 'summary' => $summary, 'notes' => $notes];
        }

        $rows = collect($data)->map(function ($d) {
            $days = collect($d['days']);
            $workdays = $days->where('is_workday', true)->where('in_progress', false);
            $present = $workdays->filter(fn ($x) => ! $x['absent'])->count();
            $late = $days->where('late_minutes', '>', 0)->count();
            $absent = $workdays->where('absent', true)->count();

            return ['employee_id' => $d['employee']->id, 'sort' => [-$absent, -$late], 'cells' => [
                'colaborador' => $d['employee']->user->name,
                'equipe' => $d['employee']->team?->name ?? '—',
                'atrasos' => $this->cell((string) $late, $late ? 'warning' : null),
                'total_atraso' => $this->hm($days->sum('late_minutes')),
                'faltas' => $this->cell((string) $absent, $absent ? 'danger' : null),
                'faltante' => $this->hm($days->sum('missing_minutes')),
                'assiduidade' => $workdays->count() ? $this->cell(round($present / $workdays->count() * 100).'%', $present < $workdays->count() ? 'warning' : 'success') : '—',
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'atrasos' => 'Dias com atraso', 'total_atraso' => 'Total de atraso', 'faltas' => 'Faltas', 'faltante' => 'Horas faltantes', 'assiduidade' => 'Assiduidade']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => $notes];
    }

    private function extras(array $data, bool $individual): array
    {
        $all = collect($data)->flatMap(fn ($d) => $d['days']);
        $summary = [
            ['label' => 'HE 50%', 'value' => $this->hm($all->sum('overtime_50_minutes')), 'tone' => 'success'],
            ['label' => 'HE 100% (folgas e feriados)', 'value' => $this->hm($all->sum('overtime_100_minutes')), 'tone' => 'success'],
            ['label' => 'Adicional noturno (22h–5h)', 'value' => $this->hm($all->sum('night_minutes'))],
            ['label' => 'Dias com mais de 2 h extras', 'value' => $all->filter(fn ($d) => $d['is_workday'] && $d['overtime_50_minutes'] > 120)->count(), 'tone' => 'warning'],
        ];
        $notes = ['Noturno em horas de relógio; a hora noturna reduzida (52 min 30 s) aparece no espelho. Folga e feriado trabalhados entram como 100%.'];

        if ($individual) {
            $rows = collect($data[0]['days'] ?? [])->filter(fn ($d) => $d['overtime_50_minutes'] + $d['overtime_100_minutes'] + $d['night_minutes'] > 0)->map(fn ($d) => ['cells' => [
                'dia' => $d['label'],
                'marcacoes' => $this->marks($d),
                'trabalhado' => $this->hm($d['worked_minutes']),
                'he50' => $d['overtime_50_minutes'] ? $this->cell($this->hm($d['overtime_50_minutes']), $d['overtime_50_minutes'] > 120 ? 'warning' : null) : '—',
                'he100' => $d['overtime_100_minutes'] ? $this->hm($d['overtime_100_minutes']) : '—',
                'noturno' => $d['night_minutes'] ? $this->hm($d['night_minutes']) : '—',
                'obs' => $d['holiday'] ? $this->badge($d['holiday'], 'info') : (! $d['is_workday'] ? $this->badge('Folga', 'info') : ''),
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'marcacoes' => 'Marcações', 'trabalhado' => 'Trabalhado', 'he50' => 'HE 50%', 'he100' => 'HE 100%', 'noturno' => 'Noturno', 'obs' => 'Obs.']), 'rows' => $rows, 'summary' => $summary, 'notes' => $notes];
        }

        $rows = collect($data)->map(function ($d) {
            $days = collect($d['days']);
            $total = $days->sum('overtime_50_minutes') + $days->sum('overtime_100_minutes');

            return ['employee_id' => $d['employee']->id, 'sort' => [-$total], 'cells' => [
                'colaborador' => $d['employee']->user->name,
                'equipe' => $d['employee']->team?->name ?? '—',
                'dias' => (string) $days->filter(fn ($x) => $x['overtime_50_minutes'] + $x['overtime_100_minutes'] > 0)->count(),
                'he50' => $this->hm($days->sum('overtime_50_minutes')),
                'he100' => $this->hm($days->sum('overtime_100_minutes')),
                'total' => $this->cell($this->hm($total), $total ? 'success' : null),
                'noturno' => $this->hm($days->sum('night_minutes')),
                'acima2h' => (string) $days->filter(fn ($x) => $x['is_workday'] && $x['overtime_50_minutes'] > 120)->count(),
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'dias' => 'Dias com extra', 'he50' => 'HE 50%', 'he100' => 'HE 100%', 'total' => 'Total de extras', 'noturno' => 'Noturno', 'acima2h' => 'Dias +2 h']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => $notes];
    }

    private function ajustes(Company $company, Collection $employees, CarbonImmutable $from, CarbonImmutable $to, bool $individual): array
    {
        $list = TimeAdjustment::forCompany($company->id)
            ->with(['employee.user:id,name', 'approver:id,name', 'timeEntry:id,marked_at'])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();
        $tones = ['pendente' => 'warning', 'aprovado' => 'success', 'rejeitado' => 'danger'];

        $summary = [
            ['label' => 'Pedidos no período', 'value' => $list->count()],
            ['label' => 'Pendentes', 'value' => $list->where('status', 'pendente')->count(), 'tone' => 'warning'],
            ['label' => 'Aprovados', 'value' => $list->where('status', 'aprovado')->count(), 'tone' => 'success'],
            ['label' => 'Rejeitados', 'value' => $list->where('status', 'rejeitado')->count(), 'tone' => 'danger'],
        ];

        if ($individual) {
            $rows = $list->map(fn ($a) => ['cells' => [
                'dia' => $a->date->format('d/m/Y'),
                'pedido' => $a->summary(),
                'motivo' => $a->reason,
                'situacao' => $this->badge(TimeAdjustment::STATUSES[$a->status] ?? ucfirst($a->status), $tones[$a->status] ?? 'secondary'),
                'decisao' => $a->approver ? $a->approver->name.($a->decision_note ? ': "'.$a->decision_note.'"' : '') : '—',
            ]])->values()->all();

            return ['columns' => $this->cols(['dia' => 'Dia', 'pedido' => 'Pedido', 'motivo' => 'Motivo', 'situacao' => 'Situação', 'decisao' => 'Decidido por']), 'rows' => $rows, 'summary' => $summary, 'notes' => []];
        }

        $rows = $employees->map(function ($e) use ($list) {
            $mine = $list->where('employee_id', $e->id);

            return ['employee_id' => $e->id, 'sort' => [-$mine->where('status', 'pendente')->count(), -$mine->count()], 'cells' => [
                'colaborador' => $e->user->name,
                'equipe' => $e->team?->name ?? '—',
                'total' => (string) $mine->count(),
                'inclusao' => (string) $mine->where('type', 'inclusao')->count(),
                'desconsideracao' => (string) $mine->where('type', 'desconsideracao')->count(),
                'alteracao' => (string) $mine->where('type', 'alteracao')->count(),
                'pendente' => $this->cell((string) $mine->where('status', 'pendente')->count(), $mine->where('status', 'pendente')->count() ? 'warning' : null),
                'aprovado' => (string) $mine->where('status', 'aprovado')->count(),
                'rejeitado' => (string) $mine->where('status', 'rejeitado')->count(),
            ]];
        });

        return ['columns' => $this->cols(['colaborador' => 'Colaborador', 'equipe' => 'Equipe', 'total' => 'Pedidos', 'inclusao' => 'Inclusões', 'desconsideracao' => 'Exclusões', 'alteracao' => 'Alterações', 'pendente' => 'Pendentes', 'aprovado' => 'Aprovados', 'rejeitado' => 'Rejeitados']), 'rows' => $this->sorted($rows), 'summary' => $summary, 'notes' => ['Ajustes não alteram a batida original: viram um tratamento aprovado (ou rejeitado) e ficam na auditoria.']];
    }

    // =====================================================================
    // Auxiliares
    // =====================================================================

    /** Texto de uma célula (para CSV/PDF). */
    public static function text(mixed $cell): string
    {
        if (is_array($cell)) {
            return isset($cell['badges']) ? implode(', ', array_column($cell['badges'], 'text')) : (string) ($cell['text'] ?? '');
        }

        return (string) $cell;
    }

    private function hm(int $minutes): string
    {
        return TimeCalculationService::formatMinutes($minutes);
    }

    private function cell(string $text, ?string $tone = null, bool $badge = false, ?string $href = null): array|string
    {
        // Só descarta o que é nulo: "0" é um valor válido (ex.: 0 batidas fora).
        return ($tone || $badge || $href) ? array_filter(['text' => $text, 'tone' => $tone, 'badge' => $badge ?: null, 'href' => $href], fn ($v) => $v !== null) : $text;
    }

    private function badge(string $text, string $tone): array
    {
        return ['text' => $text, 'tone' => $tone, 'badge' => true];
    }

    private function cols(array $labels): array
    {
        return array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($labels), $labels);
    }

    /** Geral: quem precisa de atenção primeiro, depois por nome. */
    private function sorted(Collection $rows): array
    {
        return $rows->sort(function ($a, $b) {
            $cmp = $a['sort'] <=> $b['sort'];

            return $cmp ?: strcmp(mb_strtolower($a['cells']['colaborador']), mb_strtolower($b['cells']['colaborador']));
        })->map(fn ($r) => array_diff_key($r, ['sort' => 1]))->values()->all();
    }

    private function marks(array $day): string
    {
        return implode(' ', array_map(fn ($m) => $m->format('H:i'), $day['_effective'])) ?: '—';
    }

    private function mapUrl(array $p): string
    {
        return "https://www.google.com/maps?q={$p['latitude']},{$p['longitude']}";
    }

    private function clusterKey(array $p): string
    {
        return round((float) $p['latitude'], 3).','.round((float) $p['longitude'], 3);
    }

    /** @return array<string, int> batidas fora agrupadas por ponto (~100 m) */
    private function clusters(Collection $outside): array
    {
        $groups = [];
        foreach ($outside as $p) {
            $k = $this->clusterKey($p);
            $groups[$k] = ($groups[$k] ?? 0) + 1;
        }
        arsort($groups);

        return $groups;
    }
}
