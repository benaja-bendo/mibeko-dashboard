<?php

namespace App\Console\Commands;

use App\Jobs\SendLegalWatchNotifications;
use App\Models\LegalWatchDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Rejoue les lots de veille légale réservés mais jamais diffusés.
 *
 * La veille réserve les documents (`watch_notified_at`) AVANT d'envoyer :
 * mieux vaut perdre une alerte que la doubler. Le revers, c'est qu'un lot dont
 * la diffusion échoue définitivement — worker arrêté, file purgée, FCM
 * durablement indisponible — laisserait des textes marqués « annoncés » sans
 * que personne n'ait rien reçu, et sans plus aucune voie de retour : ces
 * documents ne sont plus candidats.
 *
 * `legal_watch_dispatches` conserve la trace de chaque lot ; cette commande est
 * la voie de reprise. Elle est SÛRE à relancer : le job saute les lots déjà
 * diffusés, ne refait que l'étape inachevée, et l'unicité
 * `notifications.(user_id, dedupe_key)` interdit tout doublon in-app.
 */
class RetryLegalWatchDispatches extends Command
{
    protected $signature = 'mibeko:retry-legal-watch
                            {--dry-run : Liste les lots à rejouer sans rien remettre en file}
                            {--older-than=15 : Âge minimal du lot, en minutes (laisse le temps à la file de faire son travail)}
                            {--limit=50 : Nombre maximal de lots rejoués en une passe}';

    protected $description = 'Rejoue les lots de veille légale réservés dont la diffusion n\'a jamais abouti.';

    public function handle(): int
    {
        $olderThan = max(0, (int) $this->option('older-than'));
        $limit = max(1, (int) $this->option('limit'));

        $dispatches = LegalWatchDispatch::query()
            ->undelivered($olderThan)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($dispatches->isEmpty()) {
            $this->info('Aucun lot de veille légale en souffrance.');

            return self::SUCCESS;
        }

        $this->table(
            ['lot', 'textes', 'statut', 'tentatives', 'in-app', 'push', 'réservé le', 'dernière erreur'],
            $dispatches->map(fn (LegalWatchDispatch $dispatch) => [
                $dispatch->id,
                $dispatch->document_count,
                $dispatch->status,
                $dispatch->attempts,
                $dispatch->in_app_written_at?->toDateTimeString() ?? '—',
                $dispatch->pushes_dispatched_at?->toDateTimeString() ?? '—',
                $dispatch->created_at?->toDateTimeString() ?? '—',
                Str::limit((string) $dispatch->last_error, 60) ?: '—',
            ])->all()
        );

        if ($this->option('dry-run')) {
            $this->warn("SIMULATION — {$dispatches->count()} lot(s) seraient remis en file (aucune écriture).");

            return self::SUCCESS;
        }

        foreach ($dispatches as $dispatch) {
            SendLegalWatchNotifications::dispatch($dispatch->id);
        }

        $this->info("Lots remis en file : {$dispatches->count()}");

        return self::SUCCESS;
    }
}
