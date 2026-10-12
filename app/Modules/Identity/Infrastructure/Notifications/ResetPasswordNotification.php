<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

final class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof CanResetPassword) {
            throw new RuntimeException('La notificación de contraseña requiere una cuenta.');
        }

        $email = $notifiable->getEmailForPasswordReset();
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $url = $frontend.'/restablecer-contrasena?token='.urlencode($this->token).'&email='.urlencode($email);

        return (new MailMessage)
            ->subject('Restablece tu contraseña en Nuvora')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line('Si no fuiste tú, ignora este mensaje. El enlace vence en 60 minutos.');
    }
}
