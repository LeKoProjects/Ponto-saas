<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Admin do SaaS: cadastro de empresas, planos, ativar/bloquear. */
class CompanyController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search'));

        $companies = Company::query()
            ->withCount(['employees as employees_count' => fn ($q) => $q->where('active', true)])
            ->when($search, fn ($q) => $q->where('legal_name', 'ilike', "%{$search}%")->orWhere('trade_name', 'ilike', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
            ->orderBy('legal_name')
            ->paginate(20)
            ->withQueryString();

        $mrr = Company::where('status', 'active')->get()->sum(fn ($c) => Company::PLANS[$c->plan]['price'] ?? 0);

        return Inertia::render('Admin/Companies', [
            'companies' => $companies,
            'plans' => Company::PLANS,
            'filters' => ['search' => $search],
            'metrics' => ['active' => Company::where('status', 'active')->count(), 'mrr' => $mrr],
        ]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $company = Company::create(collect($data)->only(['cnpj', 'legal_name', 'trade_name', 'plan', 'status'])->all());
            User::create([
                'company_id' => $company->id,
                'role' => 'owner',
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ]);
            $this->audit->log('company.created', $company, null, $company->toArray(), $company->id);
        });

        return back()->with('success', 'Empresa cadastrada com o usuário dono.');
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $data = collect($request->validated())->only(['cnpj', 'legal_name', 'trade_name', 'plan', 'status'])->all();
        $this->audit->update($company, $data, 'company.updated');

        return back()->with('success', 'Empresa atualizada.');
    }
}
