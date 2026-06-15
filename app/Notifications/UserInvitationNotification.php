<?php

namespace App\Notifications;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation d'un membre d'équipe à rejoindre Mibeko.
 *
 * Envoyée en « on-demand » (l'invité n'a pas encore de compte) via
 * `Notification::route('mail', $email)->notify(...)`. Le lien embarque le token
 * en clair (jamais stocké : seule son empreinte l'est) vers la page front
 * `/auth/accept-invitation`.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public UserInvitation $invitation,
        public string $plainToken,
        public ?string $inviterName = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $url = $base.'/auth/accept-invitation?'.http_build_query([
            'token' => $this->plainToken,
            'email' => $this->invitation->email,
        ]);

        $message = (new MailMessage)
            ->subject('Mibeko — Vous êtes invité à rejoindre l\'équipe')
            ->greeting('Bonjour,');

        if ($this->inviterName !== null) {
            $message->line("{$this->inviterName} vous invite à rejoindre l'espace de gestion Mibeko.");
        } else {
            $message->line('Vous êtes invité à rejoindre l\'espace de gestion Mibeko.');
        }

        return $message
            ->line('Cliquez sur le bouton ci-dessous pour créer votre compte et définir votre mot de passe.')
            ->action('Accepter l\'invitation', $url)
            ->line('Cette invitation expire le '.$this->invitation->expires_at->translatedFormat('d F Y à H:i').'.')
            ->line('Si vous n\'attendiez pas cette invitation, vous pouvez ignorer cet email.')
            ->salutation('L\'équipe Mibeko');
    }
}
