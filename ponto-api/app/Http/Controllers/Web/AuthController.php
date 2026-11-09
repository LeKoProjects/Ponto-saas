<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }

        $user = $request->user();
        if ($user->hasRole('funcionario')) {
            Auth::logout();

            return back()->withErrors(['email' => 'Funcionários registram o ponto pelo aplicativo (PWA).']);
        }

        $request->session()->regenerate();

        // A "página pretendida" só vale se for do mesmo painel do perfil:
        // admin do SaaS nunca volta para "/" e usuário de empresa nunca vai para /admin.
        $intended = (string) $request->session()->get('url.intended', '');
        $isAdminUrl = str_starts_with(parse_url($intended, PHP_URL_PATH) ?? '', '/admin');
        if ($intended && $isAdminUrl !== $user->isSuperAdmin()) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(self::homeFor($user));
    }

    public static function homeFor(User $user): string
    {
        return $user->isSuperAdmin() ? route('admin.companies.index') : route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
