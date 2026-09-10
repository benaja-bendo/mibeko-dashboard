<?php

namespace App\Notifications;

use App\Models\PlanGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation d'activation d'un octroi Pro vendu à la main, avec accès au
 * justificatif (reçu de confirmation, pas une facture) — mibeko-dashboard#121.
 *
 * Volontairement PAS gatée par `UserSetting::allowsNotification()` : c'est la
 * preuve d'une transaction financière déjà réalisée, pas un contenu
 * optionnel — au même titre que la réinitialisation de mot de passe ou
 * l'invitation, envoyées elles aussi hors de la matrice de préférences.
 */
class PlanGrantActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PlanGrant $grant,
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

        $message = (new MailMessage)
            ->subject('Mibeko — Confirmation de votre abonnement Pro')
            ->greeting('Bonjour,')
            ->line('Votre paiement a été vérifié et votre accès Mibeko Pro est activé.')
            ->line("Période : du {$grant->starts_at->translatedFormat('j F Y')} au {$grant->ends_at->translatedFormat('j F Y')}.");

        if ($grant->amount_fcfa !== null) {
            $message->line('Montant : '.number_format($grant->amount_fcfa, 0, ',', ' ').' FCFA.');
        }

        return $message
            ->line('Cet abonnement ne se renouvelle pas automatiquement : nous vous enverrons un rappel avant son échéance, et le renouvellement se convient avec notre équipe.')
            ->action('Voir mon abonnement et télécharger le reçu', "{$base}/settings/billing")
            ->salutation("L'équipe Mibeko");
    }
}
