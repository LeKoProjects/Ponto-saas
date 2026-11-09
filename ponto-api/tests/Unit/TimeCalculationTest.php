<?php

use App\Models\WorkSchedule;
use App\Services\TimeCalculationService;
use Carbon\CarbonImmutable;

/*
 * Cenários do Sprint 4 — o cálculo precisa bater com a planilha manual.
 * Jornada: 08:00–17:00, 8h/dia, 1h de intervalo, seg–sex, tolerância 10 min.
 */

beforeEach(function () {
    $this->calc = new TimeCalculationService;
    $this->schedule = new WorkSchedule([
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'daily_minutes' => 480,
        'break_minutes' => 60, 'weekdays' => [1, 2, 3, 4, 5], 'tolerance_minutes' => 10,
    ]);
    $this->monday = CarbonImmutable::parse('2026-11-09'); // segunda-feira
    $this->at = fn (string ...$times) => array_map(fn ($t) => CarbonImmutable::parse(str_contains($t, ' ') ? $t : "2026-11-09 {$t}"), $times);
});

it('calcula um dia normal sem saldo', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('08:00', '12:00', '13:00', '17:00'));

    expect($r)->toMatchArray([
        'expected_minutes' => 480, 'worked_minutes' => 480, 'late_minutes' => 0,
        'overtime_50_minutes' => 0, 'missing_minutes' => 0, 'night_minutes' => 0, 'absent' => false, 'incomplete' => false,
    ]);
});

it('ignora variações dentro da tolerância de 10 minutos (CLT art. 58 §1º)', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('08:07', '12:00', '13:00', '17:00'));

    expect($r['worked_minutes'])->toBe(473)
        ->and($r['late_minutes'])->toBe(0)
        ->and($r['missing_minutes'])->toBe(0)
        ->and($r['balance_minutes'])->toBe(0);
});

it('registra atraso e minutos faltantes', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('08:30', '12:00', '13:00', '17:00'));

    expect($r)->toMatchArray(['worked_minutes' => 450, 'late_minutes' => 30, 'missing_minutes' => 30, 'overtime_50_minutes' => 0, 'balance_minutes' => -30]);
});

it('calcula hora extra 50% em dia útil', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('08:00', '12:00', '13:00', '19:00'));

    expect($r)->toMatchArray(['worked_minutes' => 600, 'overtime_50_minutes' => 120, 'overtime_100_minutes' => 0, 'missing_minutes' => 0]);
});

it('marca falta quando não há marcações em dia útil', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, []);

    expect($r)->toMatchArray(['absent' => true, 'worked_minutes' => 0, 'missing_minutes' => 480, 'balance_minutes' => -480]);
});

it('calcula adicional noturno entre 22h e 5h, inclusive virando o dia', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('2026-11-09 18:00', '2026-11-10 02:00'));

    expect($r['worked_minutes'])->toBe(480)
        ->and($r['night_minutes'])->toBe(240)              // 22:00–02:00
        ->and($r['night_minutes_reduced'])->toBe(274);    // 240 × 60 / 52,5
});

it('conta tudo como extra 100% em domingo ou feriado', function () {
    $sunday = CarbonImmutable::parse('2026-11-08');
    $r = $this->calc->calculateDay($this->schedule, $sunday, [CarbonImmutable::parse('2026-11-08 08:00'), CarbonImmutable::parse('2026-11-08 12:00')]);
    expect($r)->toMatchArray(['is_workday' => false, 'expected_minutes' => 0, 'overtime_100_minutes' => 240, 'absent' => false]);

    $holiday = $this->calc->calculateDay($this->schedule, $this->monday, [], isHoliday: true);
    expect($holiday)->toMatchArray(['is_workday' => false, 'absent' => false, 'missing_minutes' => 0]);
});

it('sinaliza dia incompleto com número ímpar de marcações', function () {
    $r = $this->calc->calculateDay($this->schedule, $this->monday, ($this->at)('08:00', '12:00', '13:00'));

    expect($r['incomplete'])->toBeTrue()->and($r['worked_minutes'])->toBe(240);
});

it('formata minutos como HH:MM', function () {
    expect(TimeCalculationService::formatMinutes(125))->toBe('02:05')
        ->and(TimeCalculationService::formatMinutes(-30))->toBe('-00:30');
});
