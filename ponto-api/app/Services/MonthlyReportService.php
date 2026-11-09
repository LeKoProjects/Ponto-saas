<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Espelho de ponto mensal: marcações originais + tratamentos aprovados,
 * cálculo diário e totais do mês.
 */
class MonthlyReportService
{
    private const WEEKDAY_LABELS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    public function __construct(private TimeCalculationService $calculator) {}

    public function forEmployee(Employee $employee, int $year, int $month): array
    {
        $employee->loadMissing('user', 'workSchedule', 'team');
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth();

        $entries = TimeEntry::forCompany($employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('marked_at', [$start, $end])
            ->orderBy('marked_at')
            ->get();

        $adjustments = TimeAdjustment::forCompany($employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'aprovado')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $discarded = $adjustments->where('type', 'desconsideracao')->pluck('time_entry_id')->filter()->all();
        $inclusions = $adjustments->where('type', 'inclusao')->filter(fn ($a) => $a->proposed_at);

        $holidays = Holiday::applicableTo($employee->company_id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($h) => $h->date->toDateString());

        $days = [];
        $totals = array_fill_keys(['expected_minutes', 'worked_minutes', 'late_minutes', 'overtime_50_minutes', 'overtime_100_minutes', 'missing_minutes', 'night_minutes', 'balance_minutes'], 0);
        $totals['absences'] = 0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $day = CarbonImmutable::instance($day);
            $key = $day->toDateString();

            if ($day->lt($employee->admission_date->startOfDay())) {
                continue;
            }

            $marks = [];
            foreach ($entries->filter(fn ($e) => $e->marked_at->toDateString() === $key) as $e) {
                $isDiscarded = in_array($e->id, $discarded, true);
                $marks[] = [
                    'time' => $e->marked_at->format('H:i'),
                    'at' => $e->marked_at,
                    'nsr' => $e->nsr,
                    'source' => 'original',
                    'discarded' => $isDiscarded,
                ];
            }
            foreach ($inclusions->filter(fn ($a) => $a->date->toDateString() === $key) as $a) {
                $marks[] = ['time' => $a->proposed_at->format('H:i'), 'at' => $a->proposed_at, 'nsr' => null, 'source' => 'inclusao', 'discarded' => false];
            }
            usort($marks, fn ($a, $b) => $a['at'] <=> $b['at']);

            $effective = array_map(fn ($m) => $m['at'], array_values(array_filter($marks, fn ($m) => ! $m['discarded'])));
            $holiday = $holidays->get($key);
            $result = $this->calculator->calculateDay($employee->workSchedule, $day, $effective, (bool) $holiday);

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
                if ($k !== 'absences') {
                    $totals[$k] += $result[$k];
                }
            }
            $totals['absences'] += $result['absent'] ? 1 : 0;

            $days[] = [
                ...$result,
                'label' => $day->format('d/m').' '.self::WEEKDAY_LABELS[$day->isoWeekday()],
                'holiday' => $holiday?->name,
                'marks' => array_map(fn ($m) => array_diff_key($m, ['at' => 1]), $marks),
            ];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'cpf' => $employee->maskedCpf(),
                'registration' => $employee->registration,
                'schedule' => $employee->workSchedule->name,
                'team' => $employee->team?->name,
            ],
            'period' => ['year' => $year, 'month' => $month, 'label' => $start->locale('pt_BR')->translatedFormat('F/Y')],
            'days' => $days,
            'totals' => $totals,
        ];
    }
}
