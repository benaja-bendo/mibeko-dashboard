<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

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
}
