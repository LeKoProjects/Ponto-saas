<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\DashboardService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, Tenant $tenant): Response
    {
        $user = $request->user();
        $teamId = $request->integer('team_id') ?: null;

        // Gestor sem perfil de RH vê apenas as equipes que gerencia.
        $teams = Team::query()->when($user->hasRole('gestor'), fn ($q) => $q->where('manager_user_id', $user->id))->orderBy('name')->get(['id', 'name']);
        if ($user->hasRole('gestor') && ! $teamId) {
            $teamId = $teams->first()?->id ?? -1;
        }

        return Inertia::render('Dashboard', [
            'data' => $dashboard->today($tenant->id(), $teamId),
            'teams' => $teams,
            'filters' => ['team_id' => $request->integer('team_id') ?: null],
        ]);
    }
}
