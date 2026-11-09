<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustmentRequest;
use App\Models\TimeAdjustment;
use App\Models\TimeEntry;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tratamentos de ponto. A marcação original NUNCA é alterada: o ajuste é um
 * registro à parte que o cálculo do espelho aplica por cima.
 * MVP: lançado pelo RH já aprovado. Fase 2: solicitação pelo funcionário + aprovação do gestor.
 */
class AdjustmentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $adjustments = TimeAdjustment::with('employee.user:id,name', 'requester:id,name', 'approver:id,name', 'timeEntry:id,nsr,marked_at')
            ->latest()
            ->paginate(20)
            ->through(fn (TimeAdjustment $a) => [
                'id' => $a->id,
                'employee' => $a->employee->user->name,
                'date' => $a->date->format('d/m/Y'),
                'type' => TimeAdjustment::TYPES[$a->type],
                'detail' => $a->type === 'inclusao'
                    ? 'Incluir '.$a->proposed_at?->format('H:i')
                    : 'Desconsiderar NSR '.$a->timeEntry?->nsr.' ('.$a->timeEntry?->marked_at->format('H:i').')',
                'reason' => $a->reason,
                'status' => TimeAdjustment::STATUSES[$a->status],
                'requested_by' => $a->requester->name,
                'approved_by' => $a->approver?->name,
                'created_at' => $a->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Adjustments/Index', ['adjustments' => $adjustments]);
    }

    public function store(AdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($data['type'] === 'desconsideracao') {
            $entry = TimeEntry::findOrFail($data['time_entry_id']);
            abort_unless($entry->employee_id === (int) $data['employee_id'], 422, 'Marcação não pertence ao funcionário.');
            $data['date'] = $entry->marked_at->toDateString();
        }

        $adjustment = TimeAdjustment::create([
            'employee_id' => $data['employee_id'],
            'time_entry_id' => $data['type'] === 'desconsideracao' ? $data['time_entry_id'] : null,
            'date' => $data['date'],
            'type' => $data['type'],
            'proposed_at' => $data['type'] === 'inclusao' ? CarbonImmutable::parse($data['date'].' '.$data['time']) : null,
            'reason' => $data['reason'],
            'status' => 'aprovado',
            'requested_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->audit->log('adjustment.created', $adjustment, null, $adjustment->toArray());

        return back()->with('success', 'Tratamento registrado. A marcação original foi preservada.');
    }
}
