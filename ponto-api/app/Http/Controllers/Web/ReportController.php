<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatórios do RH: geral (todos) ou individual (um colaborador), com CSV e PDF. */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $user = $request->user();
        $report = $this->reports->build($f['type'], $user->company, $user, $f['from'], $f['to'], $f['team_id'], $f['employee_id']);

        // Paginação de 20 (padrão do painel).
        $page = new LengthAwarePaginator(
            array_slice($report['rows'], ($request->integer('page', 1) - 1) * 20, 20),
            count($report['rows']), 20, $request->integer('page', 1),
            ['path' => $request->url(), 'query' => $request->except('page')],
        );

        return Inertia::render('Reports/Index', [
            'types' => ReportService::TYPES,
            'filters' => ['type' => $f['type'], 'from' => $f['from']->toDateString(), 'to' => $f['to']->toDateString(), 'team_id' => $f['team_id'], 'employee_id' => $f['employee_id']],
            'teams' => Team::query()
                ->when($user->hasRole('gestor'), fn ($q) => $q->where('manager_user_id', $user->id))
                ->orderBy('name')->get(['id', 'name']),
            'employees' => $this->reports->employees($user->company, $user, $f['team_id'], null, $f['to'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->user->name])->values(),
            'report' => [...$report, 'rows' => $page],
        ]);
    }

    public function export(Request $request, string $format): HttpResponse|StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $f = $this->filters($request);
        $user = $request->user();
        $report = $this->reports->build($f['type'], $user->company, $user, $f['from'], $f['to'], $f['team_id'], $f['employee_id']);
        $name = sprintf('relatorio-%s-%s-%s-%s', $f['type'], $report['mode'], $f['from']->format('Ymd'), $f['to']->format('Ymd'));

        $this->audit->log('report.exported', null, null, ['type' => $f['type'], 'mode' => $report['mode'], 'format' => $format, 'from' => $f['from']->toDateString(), 'to' => $f['to']->toDateString(), 'employee_id' => $f['employee_id'], 'team_id' => $f['team_id']], $user->company_id);

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.report', ['r' => $report, 'company' => $user->company])
                ->setPaper('a4', count($report['columns']) > 7 ? 'landscape' : 'portrait')
                ->download("{$name}.pdf");
        }

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre com acentos certos
            fputcsv($out, [$report['title'].' — '.$report['subtitle']], ';');
            fputcsv($out, array_column($report['columns'], 'label'), ';');
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => ReportService::text($row['cells'][$c['key']] ?? ''), $report['columns']), ';');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{type: string, from: CarbonImmutable, to: CarbonImmutable, team_id: ?int, employee_id: ?int} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(ReportService::TYPES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'team_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ], ['to.after_or_equal' => 'A data final deve ser depois da inicial.']);

        $from = CarbonImmutable::parse($data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now()->toDateString())->startOfDay();
        if ($from->diffInDays($to) + 1 > ReportService::MAX_DAYS) {
            $from = $to->subDays(ReportService::MAX_DAYS - 1);
        }

        return [
            'type' => $data['type'] ?? 'intervalo',
            'from' => $from,
            'to' => $to,
            'team_id' => isset($data['team_id']) ? (int) $data['team_id'] : null,
            'employee_id' => isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        ];
    }
}
