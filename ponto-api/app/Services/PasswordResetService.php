<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** "Esqueci minha senha" — usado pelo painel e pela API do app. */
class PasswordResetService
{
    public const SENT_MESSAGE = 'Se este e-mail estiver cadastrado, você vai receber um link para criar uma nova senha em alguns minutos. Confira também a caixa de spam.';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Envia o link. A resposta é sempre a mesma (e-mail existindo ou não),
     * para não revelar quem tem cadastro.
     */
    public function sendLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        // Empresa bloqueada não recebe link (o login também seria recusado).
        if (! $user || ($user->company_id && ! $user->company?->isActive())) {
            return;
        }

        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit->as($user->id, fn () => $this->audit->log('auth.password_reset_requested', $user, null, null, $user->company_id));
        }
    }

    /** @return string status do Password broker */
    public function reset(array $data): string
    {
        return Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // Senha nova derruba os acessos antigos do app. O aparelho continua liberado:
            // basta entrar de novo nele, sem novo pedido ao RH.
            $user->tokens()->delete();

            event(new PasswordReset($user));
            $this->audit->as($user->id, fn () => $this->audit->log('auth.password_reset', $user, null, null, $user->company_id));
        });
    }
}
