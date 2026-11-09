<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Teams/Index', [
            'teams' => Team::with('manager:id,name')->withCount('employees')->orderBy('name')->paginate(20),
            'managers' => User::where('company_id', $tenant->id())->whereIn('role', ['owner', 'rh', 'gestor'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(TeamRequest $request): RedirectResponse
    {
        $team = Team::create($request->validated());
        $this->audit->log('team.created', $team, null, $team->toArray());

        return back()->with('success', 'Equipe cadastrada.');
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $this->audit->update($team, $request->validated(), 'team.updated');

        return back()->with('success', 'Equipe atualizada.');
    }
}
