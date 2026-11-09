<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeRequest;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search'));

        $employees = Employee::query()
            ->with('user:id,name,email,role', 'workSchedule:id,name', 'team:id,name')
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$search}%"))
                    ->orWhere('cpf', 'like', '%'.preg_replace('/\D/', '', $search).'%')
                    ->orWhere('registration', 'ilike', "%{$search}%");
            }))
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->user->name,
                'email' => $e->user->email,
                'role' => User::ROLES[$e->user->role] ?? $e->user->role,
                'cpf' => $e->maskedCpf(),
                'registration' => $e->registration,
                'schedule' => $e->workSchedule?->name,
                'team' => $e->team?->name,
                'active' => $e->active,
            ]);

        return Inertia::render('Employees/Index', ['employees' => $employees, 'filters' => ['search' => $search]]);
    }

    public function create(): Response
    {
        return Inertia::render('Employees/Form', $this->formOptions());
    }

    public function store(EmployeeRequest $request, Tenant $tenant): RedirectResponse
    {
        $company = $request->user()->company;
        if (Employee::where('active', true)->count() >= $company->maxEmployees()) {
            return back()->with('error', 'Limite de funcionários do plano atingido. Faça upgrade do plano.');
        }

        $data = $request->validated();

        $employee = DB::transaction(function () use ($data, $tenant) {
            $user = User::create([
                'company_id' => $tenant->id(),
                'role' => $data['role'],
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $employee = Employee::create([
                'user_id' => $user->id,
                ...collect($data)->only(['cpf', 'registration', 'admission_date', 'work_schedule_id', 'team_id'])->all(),
                'active' => $data['active'] ?? true,
            ]);

            $this->audit->log('employee.created', $employee, null, $employee->toArray() + ['name' => $user->name, 'email' => $user->email]);

            return $employee;
        });

        return redirect()->route('employees.index')->with('success', "Funcionário {$employee->user->name} cadastrado.");
    }

    public function edit(Employee $employee): Response
    {
        $employee->load('user', 'devices.decider:id,name', 'devices.revoker:id,name');

        return Inertia::render('Employees/Form', [
            ...$this->formOptions(),
            // Histórico de aparelhos do colaborador (nunca é apagado).
            'devices' => $employee->devices->sortByDesc('requested_at')->values()->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'status' => $d->status,
                'status_label' => Device::STATUSES[$d->status],
                'requested_at' => $d->requested_at?->format('d/m/Y H:i'),
                'decided' => $d->decided_at ? $d->decided_at->format('d/m/Y H:i').($d->decider ? " por {$d->decider->name}" : '') : null,
                'revoked' => $d->revoked_at ? $d->revoked_at->format('d/m/Y H:i').($d->revoker ? " por {$d->revoker->name}" : '') : null,
                'reason' => $d->revoke_reason,
                'last_seen_at' => $d->last_seen_at?->format('d/m/Y H:i'),
            ]),
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'email' => $employee->user->email,
                'role' => $employee->user->role,
                'cpf' => $employee->cpf,
                'registration' => $employee->registration,
                'admission_date' => $employee->admission_date->toDateString(),
                'work_schedule_id' => $employee->work_schedule_id,
                'team_id' => $employee->team_id,
                'active' => $employee->active,
            ],
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $employee) {
            $userData = collect($data)->only(['name', 'email', 'role'])->all();
            if (! empty($data['password'])) {
                $userData['password'] = $data['password'];
            }
            $this->audit->update($employee->user, $userData, 'user.updated');
            $this->audit->update($employee, collect($data)->only(['cpf', 'registration', 'admission_date', 'work_schedule_id', 'team_id', 'active'])->all(), 'employee.updated');
        });

        return redirect()->route('employees.index')->with('success', 'Funcionário atualizado.');
    }

    private function formOptions(): array
    {
        return [
            'schedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
        ];
    }
}
