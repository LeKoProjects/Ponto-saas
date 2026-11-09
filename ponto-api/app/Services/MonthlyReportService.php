<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Espelho de ponto mensal: marcações originais + tratamentos APROVADOS,
 * cálculo diário, totais do mês e solicitações (de qualquer status) por dia.
 */
class MonthlyReportService
{
    private const WEEKDAY_LABELS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    public function __construct(private TimeCalculationService $calculator) {}

    public function forEmployee(Employee $employee, int $year, int $month): array
    {
        $employee->loadMissing('user', 'workSchedule', 'team');
        $schedule = $employee->workSchedule;
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth();

        $entries = TimeEntry::forCompany($employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('marked_at', [$start, $end])
            ->orderBy('marked_at')
            ->get();

        $adjustments = TimeAdjustment::forCompany($employee->company_id)
            ->with('timeEntry:id,marked_at,nsr')
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('created_at')
            ->get();

        $approved = $adjustments->where('status', 'aprovado');
        $discarded = $approved->whereIn('type', TimeAdjustment::ON_ENTRY)->pluck('time_entry_id')->filter()->all();
        $inclusions = $approved->whereIn('type', TimeAdjustment::WITH_TIME)->filter(fn ($a) => $a->proposed_at);

        $holidays = Holiday::applicableTo($employee->company_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($h) => $h->date->toDateString());

        $days = [];
        $totals = array_fill_keys(['expected_minutes', 'worked_minutes', 'late_minutes', 'overtime_50_minutes', 'overtime_100_minutes', 'missing_minutes', 'night_minutes', 'balance_minutes'], 0);
        $totals['absences'] = 0;
        $totals['pending_requests'] = $adjustments->where('status', 'pendente')->count();

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $day = CarbonImmutable::instance($day);
            $key = $day->toDateString();

            if ($day->lt($employee->admission_date->startOfDay())) {
                continue;
            }

            $marks = [];
            foreach ($entries->filter(fn ($e) => $e->marked_at->toDateString() === $key) as $e) {
                $marks[] = [
                    'id' => $e->id,
                    'time' => $e->marked_at->format('H:i'),
                    'at' => $e->marked_at,
                    'nsr' => $e->nsr,
                    'type' => $e->type,
                    'type_label' => TimeEntry::TYPES[$e->type] ?? $e->type,
                    'source' => 'original',
                    'discarded' => in_array($e->id, $discarded, true),
                ];
            }
            foreach ($inclusions->filter(fn ($a) => $a->date->toDateString() === $key) as $a) {
                $marks[] = [
                    'id' => null,
                    'time' => $a->proposed_at->format('H:i'),
                    'at' => $a->proposed_at,
                    'nsr' => null,
                    'type' => null,
                    'type_label' => $a->type === 'alteracao' ? 'Alterada por ajuste' : 'Incluída por ajuste',
                    'source' => $a->type,
                    'discarded' => false,
                ];
            }
            usort($marks, fn ($a, $b) => $a['at'] <=> $b['at']);

            $effective = array_map(fn ($m) => $m['at'], array_values(array_filter($marks, fn ($m) => ! $m['discarded'])));
            $holiday = $holidays->get($key);
            $result = $this->calculator->calculateDay($schedule, $day, $effective, (bool) $holiday);
            $result['in_progress'] = false;

            // Dia em andamento ou futuro: ainda não gera falta nem débito.
            if ($day->isToday() || $day->isFuture()) {
                $result['absent'] = false;
                $result['missing_minutes'] = 0;
                $result['balance_minutes'] = max(0, $result['balance_minutes']);
                $result['in_progress'] = $day->isToday();
                if ($day->isFuture()) {
                    $result['expected_minutes'] = 0;
                }
            }

            foreach ($totals as $k => $v) {
                if (array_key_exists($k, $result) && is_int($result[$k])) {
                    $totals[$k] += $result[$k];
                }
            }
            $totals['absences'] += $result['absent'] ? 1 : 0;

            $days[] = [
                ...$result,
                'label' => $day->format('d/m').' '.self::WEEKDAY_LABELS[$day->isoWeekday()],
                'is_future' => $day->isFuture(),
                'expected_with_break_minutes' => $result['expected_minutes'] > 0 ? $result['expected_minutes'] + $schedule->break_minutes : 0,
                'holiday' => $holiday?->name,
                'marks' => array_map(fn ($m) => array_diff_key($m, ['at' => 1]), $marks),
                'requests' => $adjustments->filter(fn ($a) => $a->date->toDateString() === $key)->map->toApiArray()->values()->all(),
            ];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'cpf' => $employee->maskedCpf(),
                'registration' => $employee->registration,
                'schedule' => $schedule->name,
                'schedule_hours' => substr($schedule->start_time, 0, 5).'–'.substr($schedule->end_time, 0, 5),
                'admission_date' => $employee->admission_date->toDateString(),
                'team' => $employee->team?->name,
            ],
            'period' => ['year' => $year, 'month' => $month, 'label' => $start->locale('pt_BR')->translatedFormat('F/Y')],
            'days' => $days,
            'totals' => $totals,
        ];
    }

    /** PDF do espelho mensal (painel e app do colaborador). */
    public function pdf(Employee $employee, int $year, int $month): \Barryvdh\DomPDF\PDF
    {
        $employee->loadMissing('company');

        return Pdf::loadView('pdf.timesheet', [
            'r' => $this->forEmployee($employee, $year, $month),
            'company' => $employee->company,
            'fmt' => fn (int $m) => TimeCalculationService::formatMinutes($m),
        ])->setPaper('a4');
    }
}
