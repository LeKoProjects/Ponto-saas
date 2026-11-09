<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Política de privacidade pública (link no login do painel e no app). */
class PrivacyController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // ?empresa=ID mostra o empregador (controlador) e o contato do encarregado dele.
        // Só dados públicos: razão social, CNPJ e e-mail do encarregado (LGPD art. 41, §1º).
        $company = $request->integer('empresa')
            ? Company::query()->whereKey($request->integer('empresa'))->first(['id', 'legal_name', 'trade_name', 'cnpj', 'privacy_email', 'logo_path'])
            : null;

        return Inertia::render('Public/Privacy', [
            'operator' => config('ponto.operator'),
            'version' => config('ponto.privacy'),
            'company' => $company ? [
                'name' => $company->legal_name,
                'cnpj' => $company->cnpj,
                'privacy_email' => $company->privacy_email,
                'logo_url' => $company->logoUrl(),
            ] : null,
        ]);
    }
}
