<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\DeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RH libera, recusa ou desconecta os aparelhos dos colaboradores. */
class DeviceController extends Controller
{
    public function __construct(private DeviceService $service) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->input('status'), ['pendente', 'aprovado', 'todos'], true) ? $request->input('status') : 'pendente';
        $search = trim((string) $request->input('search'));

        $devices = Device::query()
            ->with([
                'employee.user:id,name',
                'employee.devices' => fn ($q) => $q->where('status', 'aprovado'),
                'decider:id,name',
                'revoker:id,name',
            ])
            ->when($status !== 'todos', fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->whereHas('employee.user', fn ($u) => $u->where('name', 'ilike', "%{$search}%")))
            ->orderByRaw("CASE status WHEN 'pendente' THEN 0 WHEN 'aprovado' THEN 1 ELSE 2 END")
            ->orderByDesc('requested_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Device $d) => [
                'id' => $d->id,
                'employee' => $d->employee->user->name,
                'employee_id' => $d->employee_id,
                'name' => $d->name,
                'status' => $d->status,
                'status_label' => Device::STATUSES[$d->status],
                'requested_at' => $d->requested_at?->format('d/m/Y H:i'),
                'decided_at' => $d->decided_at?->format('d/m/Y H:i'),
                'decided_by' => $d->decider?->name,
                'revoked_at' => $d->revoked_at?->format('d/m/Y H:i'),
                'revoked_by' => $d->revoker?->name,
                'reason' => $d->revoke_reason,
                'last_seen_at' => $d->last_seen_at?->format('d/m/Y H:i'),
                'last_ip' => $d->last_ip,
                // Ao liberar um pendente, este aparelho atual será desconectado.
                'replaces' => $d->status === 'pendente' ? $d->employee->devices->first()?->name : null,
            ]);

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function approve(Request $request, Device $device): RedirectResponse
    {
        $this->service->approve($device, $request->user());

        return back()->with('success', "Aparelho liberado. Os outros aparelhos de {$device->employee->user->name} foram desconectados.");
    }

    public function reject(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->service->reject($device, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Solicitação de aparelho recusada.');
    }

    public function revoke(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->service->revoke($device, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Aparelho desconectado.');
    }
}
