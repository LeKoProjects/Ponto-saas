<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/** Painel do gestor: quem está trabalhando agora, atrasos e faltas do dia. */
class DashboardService
{
    public function today(int $companyId, ?int $teamId = null): array
    {
        $now = CarbonImmutable::now();
        $isHoliday = Holiday::applicableTo($companyId)->whereDate('date', $now->toDateString())->exists();

        $employees = Employee::forCompany($companyId)
            ->with('user:id,name', 'workSchedule', 'team:id,name')
            ->where('active', true)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->get();

        $entries = TimeEntry::forCompany($companyId)
            ->whereBetween('marked_at', [$now->startOfDay(), $now->endOfDay()])
            ->orderBy('marked_at')
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        $counters = ['trabalhando' => 0, 'intervalo' => 0, 'encerrado' => 0, 'ausente' => 0, 'aguardando' => 0, 'folga' => 0, 'atrasados' => 0];

        foreach ($employees as $emp) {
            $marks = $entries->get($emp->id, collect());
            $schedule = $emp->workSchedule;
            $isWorkday = ! $isHoliday && $schedule->worksOn($now->isoWeekday());
            $expectedStart = CarbonImmutable::parse($now->toDateString().' '.$schedule->start_time);
            $limit = $expectedStart->addMinutes($schedule->tolerance_minutes);

            $last = $marks->last();
            $status = match (true) {
                $marks->isEmpty() && ! $isWorkday => 'folga',
                $marks->isEmpty() && $now->gt($limit) => 'ausente',
                $marks->isEmpty() => 'aguardando',
                $last->type === 'intervalo_inicio' => 'intervalo',
                $marks->count() % 2 === 1 => 'trabalhando',
                default => 'encerrado',
            };

            $lateMinutes = 0;
            if ($isWorkday && $marks->isNotEmpty() && $marks->first()->marked_at->gt($limit)) {
                $lateMinutes = (int) $expectedStart->diffInMinutes($marks->first()->marked_at);
                $counters['atrasados']++;
            }

            $counters[$status]++;
            $rows[] = [
                'id' => $emp->id,
                'name' => $emp->user->name,
                'team' => $emp->team?->name,
                'status' => $status,
                'expected_start' => substr($schedule->start_time, 0, 5),
                'first_mark' => $marks->first()?->marked_at->format('H:i'),
                'last_mark' => $last?->marked_at->format('H:i'),
                'late_minutes' => $lateMinutes,
                'marks' => $marks->map(fn ($m) => $m->marked_at->format('H:i'))->values(),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['status'], $a['name']] <=> [$b['status'], $b['name']]);

        return ['date' => $now->format('d/m/Y'), 'time' => $now->format('H:i'), 'is_holiday' => $isHoliday, 'counters' => $counters, 'rows' => $rows];
    }
}
