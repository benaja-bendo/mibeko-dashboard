<?php

namespace App\Notifications;

use App\Models\PlanGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel e-mail avant l'échéance d'un octroi Pro vendu à la main —
 * mibeko-dashboard#121.
 *
 * N'affirme jamais un renouvellement automatique : Mibeko n'a ni carte ni
 * mandat prélevé pour ces octrois manuels, le renouvellement se convient à
 * chaque fois avec l'équipe (cf. `ManualBilling.tsx`, même posture côté front).
 */
class PlanGrantExpiringReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $daysUntil  Nombre de jours avant l'échéance.
     */
    public function __construct(
        public PlanGrant $grant,
        public int $daysUntil,
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
        $grant = $this->grant;
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $when = $this->daysUntil <= 1 ? 'demain' : "dans {$this->daysUntil} jours";

        return (new MailMessage)
            ->subject("Mibeko — Votre abonnement Pro expire {$when}")
            ->greeting('Bonjour,')
            ->line("Votre abonnement Mibeko Pro arrive à échéance {$when}, le {$grant->ends_at->translatedFormat('j F Y')}.")
            ->line("Il ne se renouvelle pas automatiquement : sans nouvelle démarche de votre part, l'accès Pro s'arrêtera à cette date.")
            ->action('Renouveler ou poser une question', "{$base}/settings/support?category=billing")
            ->line('Vous pouvez ajuster ces rappels depuis vos préférences de notification.')
            ->salutation("L'équipe Mibeko");
    }
}
