<?php

namespace App\Console\Commands;

use App\Notifications\FileMailBloqueeNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Surveille la file d'attente pour les notifications qui conditionnent
 * l'accès à un compte (réinitialisation de mot de passe, invitation) —
 * mibeko-dashboard#60.
 *
 * L'API répond 200 que l'e-mail parte réellement ou non (anti-énumération
 * délibérée) : un échec ou un blocage de la file serait donc muet des deux
 * côtés sans cette commande. Deux défauts distincts, à distinguer :
 *
 *   - un job a ÉCHOUÉ (`failed_jobs`) — ex. SMTP en erreur, identifiants
 *     invalides ;
 *   - un job reste BLOQUÉ dans `jobs` au-delà d'un délai raisonnable — le
 *     worker `mibeko-queue` ne tourne probablement plus. Ce cas n'écrit
 *     jamais dans `failed_jobs` : sans ce contrôle, un worker arrêté serait
 *     invisible tant qu'aucune tentative n'échoue.
 *
 * Filtre volontairement étroit sur les deux notifications d'accès au compte,
 * pas sur toute la file : d'autres jobs (extraction, embeddings, export PDF)
 * échouent ou se rattrapent pour des raisons sans rapport, et les mélanger
 * noierait le signal qui compte ici.
 */
class SurveillerFileMailCommand extends Command
{
    /** @var list<class-string> */
    private const NOTIFICATIONS_CRITIQUES = [
        PasswordResetCodeNotification::class,
        UserInvitationNotification::class,
    ];

    private const SEUIL_BLOCAGE_MINUTES = 10;

    protected $signature = 'mibeko:surveiller-file-mail';

    protected $description = "Alerte si un e-mail d'accès au compte (reset, invitation) a échoué ou reste bloqué en file.";

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
     * Recherche sur le nom court de la classe (`PasswordResetCodeNotification`),
     * jamais le nom complet : le payload JSON échappe chaque `\` du namespace
     * en `\\`, donc chercher `App\Notifications\…` ne correspond jamais au
     * texte réellement stocké. Le nom court n'a pas ce problème et reste
     * suffisamment spécifique pour ces deux classes.
     *
     * `position()` plutôt que `LIKE` : simple recherche de sous-chaîne
     * littérale, sans les subtilités d'échappement d'un motif `LIKE`.
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
            ->notify(new FileMailBloqueeNotification($echecs, $bloques));
    }
}
