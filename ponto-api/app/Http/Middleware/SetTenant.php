<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant a partir do usuário autenticado e barra empresas bloqueadas.
 */
class SetTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isSuperAdmin()) {
            $company = $user->company;

            if (! $company || ! $company->isActive()) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Empresa bloqueada. Entre em contato com o suporte.'], 403);
                }

                Auth::guard('web')->logout();

                return redirect()->route('login')->withErrors(['email' => 'Empresa bloqueada. Entre em contato com o suporte.']);
            }

            $this->tenant->set($company->id);
        }

        return $next($request);
    }
}
