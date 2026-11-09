<?php

namespace App\Services;

use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cálculo diário de jornada. Função pura (sem banco) para ser testável
 * cenário a cenário: normal, atraso, hora extra, falta e noturno.
 *
 * Regras do MVP:
 * - marcações são pareadas em ordem: (1ª,2ª), (3ª,4ª)…; ímpar = dia incompleto;
 * - tolerância (CLT art. 58 §1º): saldo do dia dentro de ±tolerância vale zero;
 * - atraso: 1ª marcação depois de início previsto + tolerância;
 * - dia sem previsão (folga/feriado): tudo que for trabalhado é extra 100%;
 * - noturno: minutos trabalhados entre 22h e 5h (hora reduzida 52'30" informada à parte).
 */
class TimeCalculationService
{
    public const NIGHT_START = 22;

    public const NIGHT_END = 5;

    /**
     * @param  array<int, CarbonInterface>  $marks  horários efetivos do dia (já com ajustes aplicados)
     * @return array<string, mixed>
     */
    public function calculateDay(WorkSchedule $schedule, CarbonInterface $date, array $marks, bool $isHoliday = false): array
    {
        $marks = collect($marks)
            ->map(fn (CarbonInterface $m) => CarbonImmutable::instance($m)->startOfMinute())
            ->sortBy(fn ($m) => $m->getTimestamp())
            ->values()
            ->all();

        $isWorkday = ! $isHoliday && $schedule->worksOn((int) $date->isoWeekday());
        $expected = $isWorkday ? $schedule->daily_minutes : 0;
        $tolerance = $schedule->tolerance_minutes;

        $worked = 0;
        $night = 0;
        for ($i = 0; $i + 1 < count($marks); $i += 2) {
            $worked += $this->minutesBetween($marks[$i], $marks[$i + 1]);
            $night += $this->nightMinutes($marks[$i], $marks[$i + 1]);
        }

        $late = 0;
        if ($isWorkday && count($marks) > 0) {
            $expectedStart = CarbonImmutable::parse($date->format('Y-m-d').' '.$schedule->start_time, $date->getTimezone());
            $delay = $this->minutesBetween($expectedStart, $marks[0]);
            $late = $delay > $tolerance ? $delay : 0;
        }

        $diff = $worked - $expected;
        if ($isWorkday && abs($diff) <= $tolerance) {
            $diff = 0;
        }

        return [
            'date' => $date->format('Y-m-d'),
            'is_workday' => $isWorkday,
            'is_holiday' => $isHoliday,
            'expected_minutes' => $expected,
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'overtime_50_minutes' => $isWorkday ? max(0, $diff) : 0,
            'overtime_100_minutes' => $isWorkday ? 0 : $worked,
            'missing_minutes' => $isWorkday ? max(0, -$diff) : 0,
            'night_minutes' => $night,
            'night_minutes_reduced' => (int) round($night * 60 / 52.5),
            'balance_minutes' => $isWorkday ? $diff : $worked,
            'absent' => $isWorkday && count($marks) === 0,
            'incomplete' => count($marks) % 2 === 1,
        ];
    }

    public function minutesBetween(CarbonInterface $a, CarbonInterface $b): int
    {
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
    }

    /** Minutos do intervalo [a, b] que caem entre 22h e 5h. */
    public function nightMinutes(CarbonInterface $a, CarbonInterface $b): int
    {
        if ($b <= $a) {
            return 0;
        }

        $total = 0;
        $day = CarbonImmutable::instance($a)->startOfDay()->subDay();
        $last = CarbonImmutable::instance($b)->startOfDay();

        while ($day <= $last) {
            $windowStart = $day->setTime(self::NIGHT_START, 0);
            $windowEnd = $day->addDay()->setTime(self::NIGHT_END, 0);

            $start = max($a->getTimestamp(), $windowStart->getTimestamp());
            $end = min($b->getTimestamp(), $windowEnd->getTimestamp());
            if ($end > $start) {
                $total += intdiv($end - $start, 60);
            }

            $day = $day->addDay();
        }

        return $total;
    }

    public static function formatMinutes(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $m = abs($minutes);

        return $sign.sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
