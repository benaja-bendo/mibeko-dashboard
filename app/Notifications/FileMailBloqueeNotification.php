<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte qu'un e-mail d'accès au compte (réinitialisation, invitation) a
 * échoué ou reste bloqué en file — mibeko-dashboard#60.
 *
 * Volontairement PAS `ShouldQueue` : si le worker de file est justement la
 * cause du problème signalé, une alerte mise en file resterait aussi muette
 * que l'e-mail qu'elle rapporte. Elle part en synchrone, dans le processus
 * de la commande planifiée elle-même.
 */
class FileMailBloqueeNotification extends Notification
{
    /**
     * @param  list<array{classe: string, quand: string}>  $echecs
     * @param  list<array{classe: string, minutes: int}>  $bloques
     */
    public function __construct(
        public array $echecs,
        public array $bloques,
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
        $message = (new MailMessage)
            ->subject('[Mibeko] File mail — échec ou blocage')
            ->greeting('Alerte file de notifications critiques.')
            ->line("Un e-mail d'accès au compte (réinitialisation de mot de passe ou invitation) est en échec ou reste bloqué en production.");

        foreach ($this->echecs as $echec) {
            $message->line("- ÉCHEC : {$echec['classe']}, le {$echec['quand']}");
        }

        foreach ($this->bloques as $bloque) {
            $message->line("- BLOQUÉ : {$bloque['classe']}, en attente depuis {$bloque['minutes']} min (le worker de file semble arrêté)");
        }

        return $message
            ->line('Vérifier : `docker ps` (mibeko-queue), la table `failed_jobs`, `docker logs mibeko-queue`.')
            ->salutation('mibeko:surveiller-file-mail');
    }
}
