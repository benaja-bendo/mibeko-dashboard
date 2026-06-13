<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code de réinitialisation de mot de passe (clients API mobile).
 *
 * Un code OTP à 6 chiffres plutôt qu'un lien : pas de dépendance aux deep
 * links pour un flux critique, saisie directe dans l'application.
 */
class PasswordResetCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Mibeko — Réinitialisation de votre mot de passe')
            ->greeting('Bonjour,')
            ->line('Vous avez demandé la réinitialisation de votre mot de passe Mibeko.')
            ->line("Votre code de vérification : **{$this->code}**")
            ->line('Ce code expire dans 15 minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email — votre mot de passe reste inchangé.')
            ->salutation('L\'équipe Mibeko');
    }
}
