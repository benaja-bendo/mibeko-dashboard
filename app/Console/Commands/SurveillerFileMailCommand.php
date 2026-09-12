<?php

namespace App\Console\Commands;

use App\Notifications\FileMailBloqueeNotification;
use App\Services\MailQueueHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Surveille la file d'attente pour les notifications qui conditionnent
 * l'accès à un compte (réinitialisation de mot de passe, invitation) —
 * mibeko-dashboard#60.
 *
 * L'API répond 200 que l'e-mail parte réellement ou non (anti-énumération
 * délibérée) : un échec ou un blocage de la file serait donc muet des deux
 * côtés sans cette commande. La mesure elle-même (deux défauts distincts,
 * échec vs blocage) vit dans `MailQueueHealthChecker`, partagée avec la
 * console `/admin/sante` (mibeko-dashboard#110).
 */
class SurveillerFileMailCommand extends Command
{
    protected $signature = 'mibeko:surveiller-file-mail';

    protected $description = "Alerte si un e-mail d'accès au compte (reset, invitation) a échoué ou reste bloqué en file.";

    public function __construct(private readonly MailQueueHealthChecker $healthChecker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $echecs = $this->healthChecker->echecs();
        $bloques = $this->healthChecker->bloques();

        if ($echecs === [] && $bloques === []) {
            $this->info('File saine : aucun échec, aucun blocage.');

            return self::SUCCESS;
        }

        foreach ($echecs as $echec) {
            $this->warn("Échec : {$echec['classe']} (échoué le {$echec['quand']})");
        }
        foreach ($bloques as $bloque) {
            $this->warn("Bloqué : {$bloque['classe']} (en attente depuis {$bloque['minutes']} min)");
        }

        $this->alerter($echecs, $bloques);

        return self::FAILURE;
    }

    /**
     * @param  list<array{classe: string, quand: string}>  $echecs
     * @param  list<array{classe: string, minutes: int}>  $bloques
     */
    private function alerter(array $echecs, array $bloques): void
    {
        $destinataire = (string) config('backup.notifications.mail.to');

        if ($destinataire === '') {
            $this->error('MAIL_TO_ADDRESS absent — alerte non envoyée.');

            return;
        }

        Notification::route('mail', $destinataire)
            ->notify(new FileMailBloqueeNotification($echecs, $bloques));
    }
}
