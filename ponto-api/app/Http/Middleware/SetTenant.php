<?php

namespace App\Http\Middleware;

use App\Support\ContextCache;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant a partir do usuário autenticado e barra empresas bloqueadas.
 * O status da empresa vem do cache (invalidado quando a empresa é salva).
 */
class SetTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isSuperAdmin()) {
            $company = ContextCache::company($user->company_id);

            if (! $company || $company['status'] !== 'active') {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Empresa bloqueada. Entre em contato com o suporte.'], 403);
                }

                Auth::guard('web')->logout();

                return redirect()->route('login')->withErrors(['email' => 'Empresa bloqueada. Entre em contato com o suporte.']);
            }

            $this->tenant->set($company['id']);
        }

        return $next($request);
    }
}
