<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\AuditLogger;
use App\Services\MonthlyReportService;
use App\Services\TimeCalculationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Espelho de ponto mensal + exportações (PDF e CSV para o contador). */
class TimesheetController extends Controller
{
    public function __construct(private MonthlyReportService $reports, private AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        [$year, $month] = $this->period($request);
        $employees = $this->employeesFor($request)->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user->name]);
        $employeeId = $request->integer('employee_id') ?: $employees->first()['id'] ?? null;

        $report = null;
        $entries = [];
        if ($employeeId) {
            $employee = $this->employeesFor($request)->findOrFail($employeeId);
            $report = $this->reports->forEmployee($employee, $year, $month);
            $entries = TimeEntry::where('employee_id', $employee->id)
                ->whereYear('marked_at', $year)->whereMonth('marked_at', $month)
                ->orderBy('marked_at')->get()
                ->map(fn ($e) => ['id' => $e->id, 'label' => $e->marked_at->format('d/m H:i').' — NSR '.$e->nsr, 'date' => $e->marked_at->toDateString()]);
        }

        return Inertia::render('Timesheet/Show', [
            'employees' => $employees,
            'report' => $report,
            'entries' => $entries,
            'filters' => ['employee_id' => $employeeId, 'month' => sprintf('%04d-%02d', $year, $month)],
        ]);
    }

    public function pdf(Request $request)
    {
        [$year, $month] = $this->period($request);
        $employee = $this->employeesFor($request)->findOrFail($request->integer('employee_id'));

        $this->audit->log('timesheet.pdf', $employee, null, ['month' => sprintf('%04d-%02d', $year, $month)]);

        return $this->reports->pdf($employee, $year, $month)
            ->download(sprintf('espelho-%s-%04d-%02d.pdf', $employee->id, $year, $month));
    }

    /** CSV para o contador: uma linha por funcionário/dia, separador ";" (Excel pt-BR). */
    public function csv(Request $request): StreamedResponse
    {
        [$year, $month] = $this->period($request);
        $employees = $this->employeesFor($request)->get();
        $this->audit->log('timesheet.csv', null, null, ['month' => sprintf('%04d-%02d', $year, $month)]);

        return response()->streamDownload(function () use ($employees, $year, $month) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Matrícula', 'Nome', 'CPF', 'Data', 'Marcações', 'Previsto', 'Trabalhado', 'Atraso', 'HE 50%', 'HE 100%', 'Faltante', 'Noturno', 'Falta', 'Feriado'], ';');
            $fmt = fn (int $m) => TimeCalculationService::formatMinutes($m);

            foreach ($employees as $employee) {
                $r = $this->reports->forEmployee($employee, $year, $month);
                foreach ($r['days'] as $d) {
                    $marks = collect($d['marks'])->reject(fn ($m) => $m['discarded'])->pluck('time')->implode(' ');
                    fputcsv($out, [
                        $r['employee']['registration'], $r['employee']['name'], $r['employee']['cpf'], $d['date'], $marks,
                        $fmt($d['expected_minutes']), $fmt($d['worked_minutes']), $fmt($d['late_minutes']),
                        $fmt($d['overtime_50_minutes']), $fmt($d['overtime_100_minutes']), $fmt($d['missing_minutes']),
                        $fmt($d['night_minutes']), $d['absent'] ? 'S' : 'N', $d['holiday'] ?? '',
                    ], ';');
                }
            }
            fclose($out);
        }, sprintf('ponto-%04d-%02d.csv', $year, $month), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(Request $request): array
    {
        $month = $request->input('month');
        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        return array_map('intval', explode('-', $month));
    }

    /** Gestor vê só as equipes que gerencia; RH/dono veem todos. */
    private function employeesFor(Request $request)
    {
        $user = $request->user();

        return Employee::query()
            ->with('user:id,name')
            ->when($user->hasRole('gestor'), fn ($q) => $q->whereHas('team', fn ($t) => $t->where('manager_user_id', $user->id)))
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*');
    }
}
