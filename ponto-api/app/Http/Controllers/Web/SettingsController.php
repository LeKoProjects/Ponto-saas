<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações da empresa, definidas pelo dono/RH. */
class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $request->user()->company;

        return Inertia::render('Settings/Index', [
            'settings' => ['require_location' => (bool) $company->require_location],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['require_location' => ['required', 'boolean']]);

        $audit->update($request->user()->company, $data, 'company.settings_updated');

        return back()->with('success', 'Configurações salvas.');
    }
}
