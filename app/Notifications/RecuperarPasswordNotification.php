<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo con el enlace para restablecer la contraseña (M01).
 * El enlace apunta al frontend (SPA), que consumirá el token en su pantalla
 * "Recuperar contraseña" (Fase 3). El token lo genera el password broker.
 */
class RecuperarPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Restablece tu contraseña')
            ->greeting('Hola '.$notifiable->nombre.',')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $this->urlRestablecer($notifiable))
            ->line("El enlace caduca en {$minutos} minutos.")
            ->line('Si no solicitaste este cambio, ignora este correo: tu contraseña no se modificará.');
    }

    /** Construye la URL del frontend con token + email. */
    private function urlRestablecer(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $email = urlencode($notifiable->getEmailForPasswordReset());

        return "{$base}/restablecer-password?token={$this->token}&email={$email}";
    }
}
