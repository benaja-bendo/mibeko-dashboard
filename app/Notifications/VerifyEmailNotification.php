<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/**
 * E-mail de vérification d'adresse, envoyé par la file d'attente.
 *
 * `VerifyEmail` de Laravel part en synchrone, pendant la requête
 * d'inscription (listener `SendEmailVerificationNotification` sur
 * `Registered`). Du 21 au 24/09/2026, le SMTP a refusé tout envoi
 * (« 550 Sender mismatch ») : l'exception remontait en 500 après la création
 * du compte mais avant celle du jeton, et 12 inscriptions sur 16 ont laissé
 * un compte sans jeton que l'usager croyait raté (mibeko-dashboard#185).
 * En file, une panne SMTP n'échoue plus que le job : l'inscription aboutit,
 * l'échec se lit dans `failed_jobs`, et `queue:retry` renvoie l'e-mail une
 * fois le SMTP rétabli.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Message rédigé en français, dans le ton des autres notifications
     * (mibeko-dashboard#187) : le `VerifyEmail` de Laravel écrit en anglais,
     * et les 15 e-mails renvoyés après la panne SMTP du 24/09/2026 sont
     * partis ainsi. La durée annoncée est celle qui signe le lien.
     *
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $duree = self::dureeDeValidite((int) Config::get('auth.verification.expire', 60));

        return (new MailMessage)
            ->subject('Mibeko — Confirmez votre adresse e-mail')
            ->greeting('Bonjour,')
            ->line('Pour finaliser votre inscription sur Mibeko, confirmez votre adresse e-mail.')
            ->action('Confirmer mon adresse e-mail', $url)
            ->line("Ce lien est valable {$duree}. Passé ce délai, connectez-vous et demandez un nouvel e-mail depuis l'application.")
            ->line('Si vous n\'avez pas créé de compte Mibeko, ignorez ce message.')
            ->salutation('L\'équipe Mibeko');
    }

    /**
     * Durée lisible : « 48 heures » plutôt que « 2880 minutes ». Partagée avec
     * la page du lien expiré, qui doit annoncer la même durée que l'e-mail.
     */
    public static function dureeDeValidite(int $minutes): string
    {
        if ($minutes < 60 || $minutes % 60 !== 0) {
            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        $heures = intdiv($minutes, 60);

        return $heures === 1 ? '1 heure' : "{$heures} heures";
    }
}
