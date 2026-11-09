<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
                'privacy_email' => $company->privacy_email,
                'logo_url' => $company->logoUrl(),
            ],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'require_location' => ['required', 'boolean'],
            'privacy_email' => ['nullable', 'email', 'max:160'],
        ]);

        $audit->update($request->user()->company, $data, 'company.settings_updated');

        return back()->with('success', 'Configurações salvas.');
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
