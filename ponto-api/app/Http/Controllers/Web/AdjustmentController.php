<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustmentRequest;
use App\Models\Employee;
use App\Models\TimeAdjustment;
use App\Services\AdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes de ponto no painel:
 * - RH/dono lançam tratamentos (já aprovados);
 * - RH/dono/gestor aprovam ou rejeitam as solicitações dos colaboradores.
 * A marcação original nunca é alterada.
 */
class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $service) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = in_array($request->input('status'), ['pendente', 'aprovado', 'rejeitado', 'todos'], true) ? $request->input('status') : 'pendente';

        $adjustments = TimeAdjustment::with('employee.user:id,name', 'requester:id,name', 'approver:id,name', 'timeEntry:id,nsr,marked_at')
            ->when($status !== 'todos', fn ($q) => $q->where('status', $status))
            ->when($user->hasRole('gestor'), fn ($q) => $q->whereHas('employee.team', fn ($t) => $t->where('manager_user_id', $user->id)))
            ->orderByRaw("CASE WHEN status = 'pendente' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (TimeAdjustment $a) => [
                'id' => $a->id,
                'employee' => $a->employee->user->name,
                'date' => $a->date->format('d/m/Y'),
                'type' => TimeAdjustment::TYPES[$a->type] ?? $a->type,
                'detail' => $a->summary(),
                'reason' => $a->reason,
                'status' => $a->status,
                'status_label' => TimeAdjustment::STATUSES[$a->status],
                'requested_by' => $a->requester->name,
                'by_employee' => $a->requested_by === $a->employee->user_id,
                'approved_by' => $a->approver?->name,
                'decision_note' => $a->decision_note,
                'created_at' => $a->created_at->format('d/m/Y H:i'),
                'can_decide' => $a->status === 'pendente' && $a->requested_by !== $user->id,
            ]);

        return Inertia::render('Adjustments/Index', ['adjustments' => $adjustments, 'filters' => ['status' => $status]]);
    }

    public function store(AdjustmentRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $this->service->create($employee, $request->user(), $request->validated(), autoApprove: true);

        return back()->with('success', 'Tratamento registrado. A marcação original foi preservada.');
    }

    public function approve(Request $request, TimeAdjustment $adjustment): RedirectResponse
    {
        return $this->decide($request, $adjustment, true);
    }

    public function reject(Request $request, TimeAdjustment $adjustment): RedirectResponse
    {
        return $this->decide($request, $adjustment, false);
    }

    private function decide(Request $request, TimeAdjustment $adjustment, bool $approve): RedirectResponse
    {
        abort_unless($this->service->canDecide($request->user(), $adjustment), 403, 'Você só pode decidir sobre as equipes que gerencia.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        $this->service->decide($adjustment, $request->user(), $approve, $data['note'] ?? null);

        return back()->with('success', $approve ? 'Solicitação aprovada.' : 'Solicitação rejeitada.');
    }
}
