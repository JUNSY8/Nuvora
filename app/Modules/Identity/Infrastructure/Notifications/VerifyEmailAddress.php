<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

final class VerifyEmailAddress extends VerifyEmail
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verifica tu correo en Nuvora')
            ->line('Confirma tu correo para crear y administrar tu empresa.')
            ->action('Verificar correo', $verificationUrl)
            ->line('Si no creaste esta cuenta, ignora este mensaje.');
    }
}
