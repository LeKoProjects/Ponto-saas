<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $service) {}

    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->service->sendLink($data['email']);

        return back()->with('success', PasswordResetService::SENT_MESSAGE);
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'As senhas não conferem.',
        ]);

        $status = $this->service->reset($data);

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Link inválido ou expirado. Peça um novo em "Esqueci minha senha".']);
        }

        return redirect()->route('login')->with('success', 'Senha alterada. Entre com a nova senha no painel ou no app de ponto.');
    }
}
