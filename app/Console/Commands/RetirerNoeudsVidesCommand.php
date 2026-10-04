<?php

namespace App\Console\Commands;

use App\Services\EmptyStructureNodeRemover;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retire d'UN texte les divisions qui ne sont qu'une ligne de sommaire que le parseur
 * a prise pour une division (dashboard#221, mibeko-python#40) : celles qui ne portent
 * aucun article dans leur sous-arbre, et, désignées une à une avec --dissoudre, celles
 * qui ont reçu en fils la vraie première division du texte (leurs descendants remontent
 * d'un niveau). Suppression logique seulement (D-045).
 *
 * Classe 2 sur un document publié : méthode en 4 temps de `docs/infra/production.md` § 6.
 * Le canal est la base directe (`pgsql_prod_rw`) : l'API n'a pas de retrait de nœuds
 * vides, et son DELETE emporterait aussi les articles du sous-arbre. La sélection,
 * ses garde-fous et le retour arrière vivent dans {@see EmptyStructureNodeRemover}.
 *
 * L'opération est atomique sur un seul document : pas de lot pilote, mais une
 * simulation qui annonce le nombre et l'empreinte des nœuds, que l'exécution
 * doit retrouver à l'identique. Sinon rien n'est écrit.
 *
 *   php artisan mibeko:retirer-noeuds-vides --document=<uuid>                                          # simulation, dev
 *   php artisan mibeko:retirer-noeuds-vides --document=<uuid> --connection=pgsql_prod_ro                # simulation, prod (lecture seule)
 *   php artisan mibeko:retirer-noeuds-vides --document=<uuid> --dissoudre=<uuid-du-noeud>              # simulation, avec un nœud à dissoudre
 *   php artisan mibeko:retirer-noeuds-vides --document=<uuid> [--dissoudre=<uuid>] --connection=pgsql_prod_rw \
 *       --attendu=<n> --empreinte=<sha256> --execute                                                   # exécution
 *   php artisan mibeko:retirer-noeuds-vides --restaurer=<fichier> --connection=pgsql_prod_rw --execute  # retour arrière
 */
class RetirerNoeudsVidesCommand extends Command
{
    protected $signature = 'mibeko:retirer-noeuds-vides
        {--document= : UUID du document à nettoyer}
        {--dissoudre=* : UUID d\'un nœud sans article direct à dissoudre : ses descendants remontent d\'un niveau, puis il est retiré}
        {--connection=pgsql : Connexion visée (pgsql_prod_ro en diagnostic, pgsql_prod_rw pour écrire)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}
        {--attendu= : Nombre de nœuds annoncé par la simulation (obligatoire avec --execute)}
        {--empreinte= : Empreinte annoncée par la simulation (obligatoire avec --execute)}
        {--revert-file= : Où écrire l\'instantané de retour arrière (défaut : storage/app/)}
        {--restaurer= : Instantané à rejouer pour rétablir les nœuds retirés}';

    protected $description = 'Retire (suppression logique) les divisions sans aucun article d\'un document, avec retour arrière.';

    public function handle(EmptyStructureNodeRemover $remover): int
    {
        $connexion = (string) $this->option('connection');
        $execute = (bool) $this->option('execute');

        if ($execute && $connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        try {
            if ((string) $this->option('restaurer') !== '') {
                return $this->restaurer($remover, $db, $connexion, $execute);
            }

            return $this->retirer($remover, $db, $connexion, $execute);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function retirer(EmptyStructureNodeRemover $remover, ConnectionInterface $db, string $connexion, bool $execute): int
    {
        $documentId = (string) $this->option('document');
        if ($documentId === '') {
            $this->error('--document=<uuid> est obligatoire.');

            return self::FAILURE;
        }

        $dissoudre = array_values(array_filter((array) $this->option('dissoudre')));
        $plan = $remover->plan($db, $documentId, $dissoudre);

        if ($plan['document'] !== null) {
            $this->line('Document : '.Str::limit((string) $plan['document']->titre_officiel, 70)
                ." ({$plan['document']->curation_status}, {$plan['document']->document_role})");
        }

        $ligne = fn (object $n, string $motif): array => [
            $motif, $n->type_unite, $n->numero, Str::limit(trim((string) $n->titre), 50), Str::limit((string) $n->tree_path, 26),
        ];
        $lignes = $plan['candidates']->map(fn (object $n): array => $ligne($n, 'vide'))
            ->merge($plan['dissolved']->map(fn (object $n): array => $ligne($n, 'dissous')));
        if ($lignes->isNotEmpty()) {
            $this->table(['Motif', 'Type', 'N°', 'Titre', 'Chemin'], $lignes);
        }
        foreach ($plan['moved'] as $move) {
            $this->line("Remonte : {$move['id']}  {$move['old_tree_path']}  →  {$move['new_tree_path']}");
        }

        $c = $plan['counts'];
        $this->line("Nœuds vivants : {$c['live_nodes']} → {$c['remaining']} ({$c['to_remove']} à retirer : {$c['empty']} vides, {$c['dissolved']} dissous · {$c['moved']} remontés) · articles vivants : {$c['live_articles']} (inchangés)");
        $this->line("Empreinte : {$plan['fingerprint']}");

        if ($plan['blockers'] === [EmptyStructureNodeRemover::NOTHING_TO_REMOVE]) {
            $this->info(EmptyStructureNodeRemover::NOTHING_TO_REMOVE);

            return self::SUCCESS;
        }

        if ($plan['blockers'] !== []) {
            foreach ($plan['blockers'] as $blocker) {
                $this->error($blocker);
            }

            return self::FAILURE;
        }

        if (! $execute) {
            $this->newLine();
            $this->info("{$c['to_remove']} nœud(s) seraient retirés sur « {$connexion} » (suppression logique, document touché).");
            $options = collect($dissoudre)->map(fn (string $id): string => "--dissoudre={$id}")->implode(' ');
            $this->warn('SIMULATION : aucune écriture. Pour exécuter : --execute '
                .trim("{$options} --attendu={$c['to_remove']} --empreinte={$plan['fingerprint']}"));

            return self::SUCCESS;
        }

        $attendu = $this->option('attendu');
        $empreinte = (string) $this->option('empreinte');
        if ($attendu === null || $empreinte === '') {
            $this->error('--execute exige --attendu et --empreinte, tels que la simulation les a annoncés.');

            return self::FAILURE;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-noeuds-vides-'.Str::before($documentId, '-').'-'.now()->format('Ymd-His').'.json'));
        file_put_contents($fichierRetour, json_encode(
            $remover->snapshot($plan, $documentId),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $resultat = $remover->remove($db, $documentId, (int) $attendu, $empreinte, $dissoudre);

        $this->info("{$resultat['removed']} nœud(s) retiré(s), {$resultat['moved']} remonté(s) sur « {$connexion} » · {$resultat['live_nodes_after']} nœud(s) et {$resultat['live_articles']} article(s) vivants.");
        $this->line("Pour annuler : php artisan mibeko:retirer-noeuds-vides --restaurer={$fichierRetour} --connection={$connexion} --execute");

        return self::SUCCESS;
    }

    private function restaurer(EmptyStructureNodeRemover $remover, ConnectionInterface $db, string $connexion, bool $execute): int
    {
        $fichier = (string) $this->option('restaurer');
        if (! is_file($fichier)) {
            $this->error("Instantané introuvable : {$fichier}");

            return self::FAILURE;
        }

        $snapshot = json_decode((string) file_get_contents($fichier), true);
        if (! is_array($snapshot)) {
            $this->error("Instantané illisible : {$fichier}");

            return self::FAILURE;
        }

        $total = count($snapshot['nodes'] ?? []);

        if (! $execute) {
            $this->info("{$total} nœud(s) seraient rétablis sur « {$connexion} ».");
            $this->warn('SIMULATION : aucune écriture. Ajouter --execute pour rétablir.');

            return self::SUCCESS;
        }

        $retablis = $remover->restore($db, $snapshot);
        $this->info("{$retablis} nœud(s) rétabli(s) sur « {$connexion} ».");

        return $retablis === $total ? self::SUCCESS : self::FAILURE;
    }
}
