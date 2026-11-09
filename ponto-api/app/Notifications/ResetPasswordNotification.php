<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail "Esqueci minha senha" (painel e app usam o mesmo link). Vai pela fila: a tela responde na hora e o tempo de resposta não revela se o e-mail existe. */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Redefinição de senha — '.config('app.name'))
            ->greeting('Olá, '.strtok($notifiable->name, ' ').'!')
            ->line('Recebemos um pedido para criar uma nova senha para o seu acesso ao sistema de ponto.')
            ->action('Criar nova senha', $url)
            ->line("Este link vale por {$minutes} minutos e só pode ser usado uma vez.")
            ->line('Depois de trocar a senha, entre de novo no app de ponto. Se o aparelho já estava liberado pelo RH, você não precisa pedir nova liberação.')
            ->line('Se não foi você que pediu, ignore este e-mail: sua senha atual continua valendo.')
            ->salutation('Equipe '.config('app.name'));
    }
}
