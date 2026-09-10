<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte qu'un e-mail de facturation (confirmation d'achat, rappel
 * d'échéance) a échoué ou reste bloqué en file — mibeko-dashboard#121, même
 * rôle que `FileMailBloqueeNotification` pour les e-mails d'accès au compte.
 *
 * Volontairement PAS `ShouldQueue`, pour la même raison que son homologue :
 * si le worker de file est justement la cause du problème signalé, une
 * alerte mise en file resterait aussi muette que l'e-mail qu'elle rapporte.
 */
class FacturationMailBloqueeNotification extends Notification
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
            ->subject('[Mibeko] File mail — facturation')
            ->greeting('Alerte file de notifications de facturation.')
            ->line("Un e-mail de facturation (confirmation d'achat ou rappel d'échéance d'abonnement) est en échec ou reste bloqué en production.");

        foreach ($this->echecs as $echec) {
            $message->line("- ÉCHEC : {$echec['classe']}, le {$echec['quand']}");
        }

        foreach ($this->bloques as $bloque) {
            $message->line("- BLOQUÉ : {$bloque['classe']}, en attente depuis {$bloque['minutes']} min (le worker de file semble arrêté)");
        }

        return $message
            ->line('Un titulaire n\'a alors reçu ni sa confirmation d\'achat, ni son rappel d\'échéance. Vérifier : `docker ps` (mibeko-queue), la table `failed_jobs`, `docker logs mibeko-queue`.')
            ->salutation('mibeko:surveiller-file-facturation');
    }
}
