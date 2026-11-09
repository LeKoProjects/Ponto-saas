<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Web\AuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('role:owner,rh')
 *
 * Navegação (GET no painel) para uma tela de outro perfil leva à página
 * inicial do próprio perfil; API e ações (POST) recebem 403.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user?->hasRole(...$roles)) {
            return $next($request);
        }

        $canUsePanel = $user && ! $user->hasRole('funcionario');
        if ($canUsePanel && $request->isMethod('GET') && ! $request->expectsJson()) {
            $home = AuthController::homeFor($user);

            if (rtrim($request->url(), '/') !== rtrim($home, '/')) {
                return redirect($home);
            }
        }

        abort(403, 'Acesso não permitido para o seu perfil.');
    }
}
