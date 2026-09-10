<?php

namespace App\Console\Commands;

use App\Notifications\FacturationMailBloqueeNotification;
use App\Notifications\PlanGrantActivatedNotification;
use App\Notifications\PlanGrantExpiringReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Surveille la file d'attente pour les notifications de facturation
 * (confirmation d'achat, rappel d'échéance d'un octroi Pro) —
 * mibeko-dashboard#121.
 *
 * Même mécanique que `mibeko:surveiller-file-mail` (#60), volontairement
 * dupliquée plutôt que généralisée : ce sont deux familles de notifications
 * sans rapport (accès au compte vs argent), et `SurveillerFileMailCommand`
 * documente déjà pourquoi son filtre reste étroit — les y mélanger noierait
 * le signal des deux côtés.
 */
class SurveillerFileFacturationCommand extends Command
{
    /** @var list<class-string> */
    private const NOTIFICATIONS_CRITIQUES = [
        PlanGrantActivatedNotification::class,
        PlanGrantExpiringReminderNotification::class,
    ];

    private const SEUIL_BLOCAGE_MINUTES = 10;

    protected $signature = 'mibeko:surveiller-file-facturation';

    protected $description = "Alerte si un e-mail de facturation (confirmation d'achat, rappel d'échéance) a échoué ou reste bloqué en file.";

    public function handle(): int
    {
        $echecs = $this->rechercherEchecs();
        $bloques = $this->rechercherBloques();

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
     * @return list<array{classe: string, quand: string}>
     */
    private function rechercherEchecs(): array
    {
        $lignes = DB::table('failed_jobs')
            ->where(fn ($q) => $this->filtrerParClasse($q))
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['payload', 'failed_at']);

        return $lignes->map(fn ($ligne) => [
            'classe' => $this->extraireClasse((string) $ligne->payload),
            'quand' => (string) $ligne->failed_at,
        ])->all();
    }

    /**
     * @return list<array{classe: string, minutes: int}>
     */
    private function rechercherBloques(): array
    {
        $seuil = now()->subMinutes(self::SEUIL_BLOCAGE_MINUTES)->timestamp;

        $lignes = DB::table('jobs')
            ->where('created_at', '<', $seuil)
            ->where(fn ($q) => $this->filtrerParClasse($q))
            ->get(['payload', 'created_at']);

        return $lignes->map(fn ($ligne) => [
            'classe' => $this->extraireClasse((string) $ligne->payload),
            'minutes' => intdiv(now()->timestamp - (int) $ligne->created_at, 60),
        ])->all();
    }

    /**
     * Recherche sur le nom court de la classe, pas le nom complet — voir
     * `SurveillerFileMailCommand::filtrerParClasse()` pour le détail
     * (échappement des `\` dans le payload JSON).
     */
    private function filtrerParClasse(mixed $query): void
    {
        $query->where(function ($q) {
            foreach (self::NOTIFICATIONS_CRITIQUES as $classe) {
                $q->orWhereRaw('position(? in payload) > 0', [class_basename($classe)]);
            }
        });
    }

    private function extraireClasse(string $payload): string
    {
        foreach (self::NOTIFICATIONS_CRITIQUES as $classe) {
            if (str_contains($payload, class_basename($classe))) {
                return class_basename($classe);
            }
        }

        return 'notification inconnue';
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
            ->notify(new FacturationMailBloqueeNotification($echecs, $bloques));
    }
}
