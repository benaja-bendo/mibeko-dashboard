<?php

namespace App\Notifications;

use App\Models\DossierEcheance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rappel e-mail d'une échéance de dossier à approche de sa date.
 *
 * Posture assistive : le message rappelle l'échéance et invite l'avocat à
 * vérifier, sans se substituer à son suivi (cf. stratégie dossiers).
 */
class EcheanceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $daysUntil  Nombre de jours avant l'échéance (0 = aujourd'hui).
     */
    public function __construct(
        public DossierEcheance $echeance,
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
        $echeance = $this->echeance;
        $dossier = $echeance->dossier;
        $date = $echeance->due_date?->translatedFormat('l j F Y');

        $when = match (true) {
            $this->daysUntil <= 0 => "aujourd'hui",
            $this->daysUntil === 1 => 'demain',
            default => "dans {$this->daysUntil} jours",
        };

        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return (new MailMessage)
            ->subject("Mibeko — Échéance {$when} : {$echeance->title}")
            ->greeting('Bonjour,')
            ->line("Une échéance de votre dossier « {$dossier?->name} » arrive {$when}.")
            ->line("**{$echeance->title}**".($date ? " — {$date}" : ''))
            ->action('Ouvrir le dossier', "{$base}/app/dossiers?open={$dossier?->id}")
            ->line('Rappel indicatif : vérifiez la date et les délais applicables.')
            ->salutation("L'équipe Mibeko");
    }
}
