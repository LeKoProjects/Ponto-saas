<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WorkLocation;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações da empresa, definidas pelo dono/RH. */
class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $request->user()->company;

        return Inertia::render('Settings/Index', [
            'settings' => [
                'require_location' => (bool) $company->require_location,
                'geofence_enabled' => (bool) $company->geofence_enabled,
                'privacy_email' => $company->privacy_email,
                'logo_url' => $company->logoUrl(),
            ],
            'locations' => WorkLocation::orderBy('name')->get(['id', 'name', 'latitude', 'longitude', 'radius_m']),
            'radius' => ['min' => WorkLocation::MIN_RADIUS, 'max' => WorkLocation::MAX_RADIUS],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'require_location' => ['required', 'boolean'],
            'geofence_enabled' => ['sometimes', 'boolean'],
            'privacy_email' => ['nullable', 'email', 'max:160'],
        ]);

        $company = $request->user()->company;
        if (($data['geofence_enabled'] ?? false) && ! WorkLocation::exists()) {
            throw ValidationException::withMessages(['geofence_enabled' => 'Cadastre pelo menos um local antes de restringir a batida.']);
        }
        // Restringir por local exige a localização na batida.
        if ($data['geofence_enabled'] ?? false) {
            $data['require_location'] = true;
        }

        $audit->update($company, $data, 'company.settings_updated');

        return back()->with('success', 'Configurações salvas.');
    }

    /** Cadastra um local permitido para bater o ponto (cerca virtual). */
    public function storeLocation(Request $request, AuditLogger $audit): RedirectResponse
    {
        $location = WorkLocation::create($this->validateLocation($request));
        $audit->log('work_location.created', $location, null, $location->toContext());

        return back()->with('success', "Local \"{$location->name}\" cadastrado.");
    }

    public function updateLocation(Request $request, WorkLocation $location, AuditLogger $audit): RedirectResponse
    {
        $audit->update($location, $this->validateLocation($request), 'work_location.updated');

        return back()->with('success', "Local \"{$location->name}\" atualizado.");
    }

    public function destroyLocation(Request $request, WorkLocation $location, AuditLogger $audit): RedirectResponse
    {
        if ($request->user()->company->geofence_enabled && WorkLocation::count() === 1) {
            return back()->with('error', 'Este é o último local cadastrado. Desligue a restrição por local antes de removê-lo.');
        }

        $before = $location->toContext();
        $location->delete();
        $audit->log('work_location.deleted', $location, $before, null);

        return back()->with('success', "Local \"{$before['name']}\" removido.");
    }

    private function validateLocation(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:'.WorkLocation::MIN_RADIUS.','.WorkLocation::MAX_RADIUS],
        ], [
            'latitude.required' => 'Informe as coordenadas do local (cole do Google Maps ou use a sua localização atual).',
            'radius_m.between' => 'O raio deve ficar entre '.WorkLocation::MIN_RADIUS.' m e '.WorkLocation::MAX_RADIUS.' m.',
        ]);
    }

    /** Logo da empresa: aparece no painel, no app do colaborador, no espelho em PDF e no e-mail do comprovante. */
    public function uploadLogo(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'logo' => [
                'required', 'file', 'max:1024', // 1 MB
                Rule::imageFile()->types(['png', 'jpg', 'jpeg', 'webp'])->dimensions(Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(2000)->maxHeight(2000)),
            ],
        ], [
            'logo.max' => 'A imagem deve ter no máximo 1 MB.',
            'logo.dimensions' => 'A imagem deve ter entre 64 e 2000 pixels de largura e altura.',
        ]);

        $company = $request->user()->company;
        $old = $company->logo_path;

        // Nome novo a cada envio: evita cache antigo no navegador/app.
        $path = $request->file('logo')->store("logos/{$company->id}", 'public');
        $audit->update($company, ['logo_path' => $path], 'company.logo_updated');

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', 'Logo atualizada.');
    }

    public function removeLogo(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = $request->user()->company;

        if ($company->logo_path) {
            $old = $company->logo_path;
            $audit->update($company, ['logo_path' => null], 'company.logo_removed');
            Storage::disk('public')->delete($old);
        }

        return back()->with('success', 'Logo removida.');
    }
}
