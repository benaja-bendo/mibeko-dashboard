<?php

namespace App\Console\Commands;

use App\Notifications\FileMailBloqueeNotification;
use App\Services\MailQueueHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
    /** Plus ancien, un échec relève de l'historique et ne déclenche plus d'alerte. */
    private const FENETRE_ECHECS_HEURES = 24;

    /** Plus grand `failed_jobs.id` déjà signalé : une alerte ne part qu'une fois par échec. */
    private const CLE_DERNIER_ECHEC_SIGNALE = 'mail-queue:dernier-echec-signale';

    protected $signature = 'mibeko:surveiller-file-mail';

    protected $description = "Alerte si un e-mail d'accès au compte (reset, invitation) a échoué ou reste bloqué en file.";

    public function __construct(private readonly MailQueueHealthChecker $healthChecker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $echecs = $this->echecsNonSignales();
        $bloques = $this->healthChecker->bloques();

        if ($echecs === [] && $bloques === []) {
            $this->info('File saine : aucun échec récent non signalé, aucun blocage.');

            return self::SUCCESS;
        }

        foreach ($echecs as $echec) {
            $this->warn("Échec : {$echec['classe']} (échoué le {$echec['quand']})");
        }
        foreach ($bloques as $bloque) {
            $this->warn("Bloqué : {$bloque['classe']} (en attente depuis {$bloque['minutes']} min)");
        }

        if ($this->alerter($echecs, $bloques) && $echecs !== []) {
            Cache::forever(self::CLE_DERNIER_ECHEC_SIGNALE, max(array_column($echecs, 'id')));
        }

        return self::FAILURE;
    }

    /**
     * Un échec est un événement, pas un état : `failed_jobs` le garde à vie,
     * alors que l'alerte n'a de sens qu'à la première fois où on le voit.
     * Mesuré en production le 02/10/2026 : 3 échecs du 22/09 avaient déclenché
     * ~700 alertes, une toutes les 15 minutes depuis le retour du SMTP
     * (mibeko-dashboard#224).
     *
     * @return list<array{id: int, classe: string, quand: string}>
     */
    private function echecsNonSignales(): array
    {
        $dejaSignale = (int) Cache::get(self::CLE_DERNIER_ECHEC_SIGNALE, 0);

        return array_values(array_filter(
            $this->healthChecker->echecs(depuis: now()->subHours(self::FENETRE_ECHECS_HEURES)),
            fn (array $echec): bool => $echec['id'] > $dejaSignale,
        ));
    }

    /**
     * Vrai seulement si l'alerte est réellement partie : sinon l'échec reste à
     * signaler au passage suivant. Un envoi qui plante (SMTP en panne) lève une
     * exception avant que l'appelant n'avance sa mémoire, ce qui a le même effet.
     *
     * @param  list<array{id: int, classe: string, quand: string}>  $echecs
     * @param  list<array{classe: string, minutes: int}>  $bloques
     */
    private function alerter(array $echecs, array $bloques): bool
    {
        $destinataire = (string) config('backup.notifications.mail.to');

        if ($destinataire === '') {
            $this->error('MAIL_TO_ADDRESS absent — alerte non envoyée.');

            return false;
        }

        Notification::route('mail', $destinataire)
            ->notify(new FileMailBloqueeNotification($echecs, $bloques));

        return true;
    }
}
