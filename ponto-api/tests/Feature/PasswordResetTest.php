<?php

use App\Models\AuditLog;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/* Esqueci minha senha: painel e app usam o mesmo e-mail e a mesma página de nova senha. */

beforeEach(function () {
    Notification::fake();
    $this->c = makeCompany('Lambda');
    $this->user = $this->c['employee']->user;
});

it('envia o e-mail de redefinição pelo painel', function () {
    $this->post('/esqueci-senha', ['email' => $this->c['rh']->email])
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo($this->c['rh'], ResetPasswordNotification::class, function ($n) {
        $mail = $n->toMail($this->c['rh']);

        return str_contains($mail->actionUrl, '/redefinir-senha/') && $mail->subject === 'Redefinição de senha — '.config('app.name');
    });
    expect(AuditLog::where('action', 'auth.password_reset_requested')->exists())->toBeTrue();
});

it('envia o e-mail pelo app e não revela se o e-mail existe', function () {
    $ok = $this->postJson('/api/esqueci-senha', ['email' => $this->user->email])->assertOk()->json('message');
    $unknown = $this->postJson('/api/esqueci-senha', ['email' => 'ninguem@teste.test'])->assertOk()->json('message');

    expect($ok)->toBe($unknown);
    Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

it('não envia link para empresa bloqueada', function () {
    $this->c['company']->update(['status' => 'blocked']);
    $this->postJson('/api/esqueci-senha', ['email' => $this->user->email])->assertOk();

    Notification::assertNothingSent();
});

it('troca a senha pelo link, derruba os tokens do app e mantém o aparelho liberado', function () {
    $token = apiToken($this->user);
    $this->postJson('/api/esqueci-senha', ['email' => $this->user->email]);

    $resetToken = null;
    Notification::assertSentTo($this->user, ResetPasswordNotification::class, function ($n) use (&$resetToken) {
        $resetToken = $n->token;

        return true;
    });

    $this->get("/redefinir-senha/{$resetToken}?email=".urlencode($this->user->email))
        ->assertInertia(fn ($p) => $p->component('Auth/ResetPassword')->where('email', $this->user->email));

    $this->post('/redefinir-senha', [
        'token' => $resetToken,
        'email' => $this->user->email,
        'password' => 'NovaSenha123',
        'password_confirmation' => 'NovaSenha123',
    ])->assertRedirect('/login')->assertSessionHas('success');

    expect(Hash::check('NovaSenha123', $this->user->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::count())->toBe(0)
        ->and($this->user->employee->devices()->value('status'))->toBe('aprovado');

    $this->withToken($token)->postJson('/api/me')->assertUnauthorized();
});

it('recusa link inválido e senha fraca', function () {
    $this->post('/redefinir-senha', ['token' => 'x', 'email' => $this->user->email, 'password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])
        ->assertSessionHasErrors('email');
    $this->post('/redefinir-senha', ['token' => 'x', 'email' => $this->user->email, 'password' => '1234', 'password_confirmation' => '1234'])
        ->assertSessionHasErrors('password');
});
