<?php

namespace App\Console\Commands;

use App\Services\EffaceurContenuSupprime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Efface ce que l'usager avait supprimé mais que la base gardait avant le
 * correctif de dashboard#205 (décision du 25/09/2026, `docs/decisions.md`) :
 * le contenu des dossiers et des échéances supprimés (le tombstone reste, vidé)
 * et les avis des conversations supprimées.
 *
 * Depuis ce correctif, chaque suppression efface elle-même ce contenu
 * (`Dossier::booted`, `DossierEcheance::booted`, `AgentConversation::booted`).
 * Cette commande rattrape l'existant ; ensuite, sa simulation doit rester à
 * zéro. Si elle trouve quelque chose, un chemin de suppression échappe au
 * correctif.
 *
 *   php artisan mibeko:effacer-contenus-supprimes                       # simulation
 *   php artisan mibeko:effacer-contenus-supprimes --limit=5 --execute   # lot pilote
 *   php artisan mibeko:effacer-contenus-supprimes --execute
 *
 * En production depuis un poste : `--connection=pgsql_prod_rw`, méthode du
 * `docs/infra/production.md` § 6 (la simulation accepte `pgsql_prod_ro`).
 * L'effacement des annexes et des avis est un `DELETE` physique : cette
 * commande est, avec la purge des comptes, la seule exception admise
 * (§ 6, point 12).
 */
class EffacerContenusSupprimesCommand extends Command
{
    protected $signature = 'mibeko:effacer-contenus-supprimes
        {--connection= : Connexion cible (défaut : celle de l\'appli ; pgsql_prod_rw pour la production depuis un poste)}
        {--limit=0 : Ne traiter que les N plus anciens de chaque sorte — dossiers, échéances, avis (lot pilote)}
        {--execute : Efface réellement. Sans cette option, simulation seule.}';

    protected $description = 'Efface le contenu des dossiers et échéances supprimés, et les avis des conversations supprimées, que la base gardait.';

    public function handle(): int
    {
        $connexion = (string) ($this->option('connection') ?: config('database.default'));
        $execute = (bool) $this->option('execute');

        if ($execute && $connexion === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : il suffit à la simulation, l\'effacement exige pgsql_prod_rw.');

            return self::FAILURE;
        }

        $limite = max(0, (int) $this->option('limit'));
        $effaceur = new EffaceurContenuSupprime(DB::connection($connexion));

        $dossiers = $effaceur->dossiersAEffacer($limite);
        $echeances = $effaceur->echeancesAEffacer($limite);
        $avis = $effaceur->avisOrphelins($limite);

        $this->info(sprintf(
            'Sur « %s » : %d dossier(s) supprimé(s) à vider, %d échéance(s) supprimée(s) à vider, %d avis orphelin(s).',
            $connexion,
            count($dossiers),
            count($echeances),
            count($avis),
        ));

        $annonce = $this->lignes($effaceur->mesurerDossiers($dossiers), count($echeances), count($avis));

        if ($annonce === []) {
            $this->info('Rien à effacer.');

            return self::SUCCESS;
        }

        if (! $execute) {
            $this->afficher($annonce);
            $this->warn('SIMULATION — rien n\'est effacé. Ajouter --execute pour effacer.');

            return self::SUCCESS;
        }

        $fait = $this->lignes(
            $effaceur->effacerDossiers($dossiers),
            $effaceur->effacerEcheances($echeances),
            $effaceur->effacerAvis($avis),
        );
        $this->afficher($annonce, $fait);

        // Mesure d'après, sans limite : ne reste que ce que le lot pilote a
        // laissé de côté.
        $this->info(sprintf(
            'Après passage : %d dossier(s), %d échéance(s), %d avis orphelin(s) (avant : %d, %d, %d).',
            count($effaceur->dossiersAEffacer()),
            count($effaceur->echeancesAEffacer()),
            count($effaceur->avisOrphelins()),
            count($dossiers),
            count($echeances),
            count($avis),
        ));

        if ($fait !== $annonce) {
            $this->error('Écart entre l\'annoncé et le fait : à traiter comme un incident (production.md § 6, Temps 4).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Lignes touchées, par table et par effet.
     *
     * @param  array<string, int>  $dossiers
     * @return array<string, int> clé « table|effet »
     */
    private function lignes(array $dossiers, int $echeances, int $avis): array
    {
        $lignes = [];

        foreach ($dossiers as $table => $nombre) {
            $effet = match (true) {
                $table === 'dossiers' => 'vidées, tombstone gardé',
                in_array($table, EffaceurContenuSupprime::ANNEXES, true) => 'effacées (annexes d\'un dossier supprimé)',
                default => 'valeurs vidées (événement et date gardés)',
            };
            $lignes["{$table}|{$effet}"] = $nombre;
        }

        $lignes['dossier_echeances|vidées, tombstone gardé (supprimées seules)'] = $echeances;
        $lignes['agent_message_feedback|effacées (conversation supprimée)'] = $avis;

        ksort($lignes);

        return array_filter($lignes);
    }

    /**
     * @param  array<string, int>  $annonce
     * @param  array<string, int>|null  $fait
     */
    private function afficher(array $annonce, ?array $fait = null): void
    {
        $this->table(
            $fait === null ? ['Table', 'Effet', 'Lignes'] : ['Table', 'Effet', 'Annoncé', 'Fait'],
            array_map(function (string $cle) use ($annonce, $fait) {
                [$table, $effet] = explode('|', $cle, 2);

                return $fait === null
                    ? [$table, $effet, $annonce[$cle]]
                    : [$table, $effet, $annonce[$cle] ?? 0, $fait[$cle] ?? 0];
            }, array_keys($annonce + ($fait ?? []))),
        );
    }
}
