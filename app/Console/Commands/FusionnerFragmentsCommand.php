<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recolle un article tronqué et rapatrie les articles suivants perdus dans un
 * document orphelin, sur les documents PUBLIÉS d'un même Journal officiel.
 *
 * Découverte le 03/08/2026 : un découpage MinerU coupe systématiquement au
 * même endroit sur une série d'arrêtés d'un même JO (toujours en plein
 * article 7, sur la même clause de suspension/retrait). La première moitié
 * reste sur le document publié ; la seconde devient un « document » à part,
 * son titre étant le début de cette clause coupée — d'où des dizaines de
 * documents partageant un intitulé identique et sans rapport avec leur objet
 * réel. **Contrairement à tous les autres correctifs de cette semaine, ceci
 * touche des documents déjà PUBLIÉS et consultés** : leur article 7 est
 * aujourd'hui tronqué en pleine phrase, et il leur manque les articles
 * suivants ainsi que la signature.
 *
 * Canal DB direct : `POST /api/v1/articles` (le seul endpoint de création)
 * exige un `parent_node_id` pointant un `structure_nodes` existant — ces
 * arrêtés n'en ont aucun (structure plate), donc aucun canal API n'existe
 * pour cette opération (vérifié le 03/08/2026).
 *
 * Appariement tête/fragment : un JO produit un lot de fragments partageant le
 * même intitulé (la clause coupée), numérotés `..._acte_1`, `..._acte_2`, …
 * dans `document_key`, dans le même ordre que les arrêtés qu'ils complètent.
 * Chaque paire est vérifiée structurellement avant d'être proposée (jamais
 * supposée sur le seul rang) :
 *   - le dernier article numérique de la tête ne se termine PAS par une
 *     ponctuation forte (signe de troncature) ;
 *   - le premier morceau du fragment n'est pas numéroté (typiquement étiqueté
 *     PREAMBULE par erreur) et commence par une minuscule (signe de suite) ;
 *   - les articles numérotés du fragment reprennent exactement à
 *     (dernier numéro de la tête + 1).
 * Toute paire qui échoue un de ces contrôles est écartée de la liste
 * exécutable et signalée pour revue individuelle — jamais devinée.
 *
 *   php artisan mibeko:fusionner-fragments --connection=pgsql_prod_ro \
 *       --titre-fragment="arrêté pourra faire l’objet d’une suspension ou d’un" \
 *       --rapport=storage/app/rapport.json --plan=storage/app/plan.json
 *   php artisan mibeko:fusionner-fragments --plan=storage/app/plan.json --connection=pgsql_prod_rw --execute
 */
class FusionnerFragmentsCommand extends Command
{
    protected $signature = 'mibeko:fusionner-fragments
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en diagnostic, pgsql_prod_rw pour écrire)}
        {--titre-fragment= : Intitulé exact partagé par les fragments à rattacher}
        {--rapport= : Fichier où écrire le rapport complet (paires fiables et écartées)}
        {--plan= : Fichier où écrire le plan d\'exécution (paires fiables uniquement)}
        {--execute : Applique réellement le plan. Sans cette option, diagnostic seul.}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}';

    protected $description = 'Recolle les articles tronqués sur une série de documents PUBLIÉS d\'un même JO (lecture seule par défaut).';

    public function handle(): int
    {
        if ((bool) $this->option('execute')) {
            return $this->executer();
        }

        return $this->diagnostiquer();
    }

    private function diagnostiquer(): int
    {
        $titre = (string) $this->option('titre-fragment');

        if ($titre === '') {
            $this->error('Option --titre-fragment obligatoire : l\'intitulé exact partagé par les fragments.');

            return self::FAILURE;
        }

        $db = DB::connection((string) $this->option('connection'));

        $fragments = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->where('titre_officiel', $titre)
            ->select(['id', 'official_journal_id', 'document_key'])
            ->orderBy('official_journal_id')
            ->orderBy('document_key')
            ->get();

        if ($fragments->isEmpty()) {
            $this->warn('Aucun document ne porte cet intitulé.');

            return self::SUCCESS;
        }

        $resultats = collect();

        foreach ($fragments->groupBy('official_journal_id') as $joId => $fragmentsDuJo) {
            // Trié par le numéro de l'acte lui-même (« Arrêté n° 1550 » → 1550),
            // PAS par son dernier article : dans ce cluster, tous les derniers
            // articles valent « 7 » — un tri sur ce champ ne trierait rien.
            //
            // Filtré aux SEULS candidats réellement tronqués (dernier article
            // sans ponctuation forte) AVANT l'appariement par position — pas
            // après. Un JO mélange des arrêtés touchés par le bug et d'autres
            // qui ne le sont pas (même numérotés dans l'intervalle) : les
            // laisser dans le pool avant filtrage décale tous les index
            // suivants d'autant de rangs, et des membres légitimes du cluster
            // tombent hors de la fenêtre `$n = min(têtes, fragments)` sans
            // jamais être testés. Constaté en prod : 3 arrêtés non tronqués
            // intercalés (1556-1558) poussaient 3 vrais membres du cluster
            // (1562-1564) hors de portée.
            $candidats = $db->table('legal_documents')
                ->whereNull('deleted_at')
                ->where('official_journal_id', $joId)
                ->where('id', '!=', $fragmentsDuJo->first()->id)
                ->whereRaw('titre_officiel != ?', [$titre])
                ->select(['id', 'titre_officiel', 'curation_status'])
                ->get()
                ->filter(fn ($t) => $this->numeroDActe($t->titre_officiel) !== null)
                ->sortBy(fn ($t) => $this->numeroDActe($t->titre_officiel))
                ->values();

            $tetes = collect();

            foreach ($candidats as $candidat) {
                $dernier = $this->dernierArticleNumerique($db, $candidat->id);

                // Les deux motifs d'exclusion sont vérifiés ICI, avant tout
                // appariement — pas dans verifierPaire() — précisément pour
                // qu'un candidat écarté ne consomme jamais de rang et ne
                // décale donc jamais l'appariement des candidats suivants.
                if ($dernier === null) {
                    $resultats->push($this->resultat($candidat, (object) ['id' => null], null, 'ecarte', 'aucun article numérique — structure trop différente pour appartenir à ce cluster'));

                    continue;
                }

                if (preg_match('/[.;:!]$/u', trim($dernier['contenu'])) === 1) {
                    $resultats->push($this->resultat($candidat, (object) ['id' => null], $dernier, 'ecarte', 'le dernier article de la tête se termine par une ponctuation forte — pas de troncature détectée, ce document n\'appartient probablement pas au cluster'));

                    continue;
                }

                $tetes->push($candidat);
            }

            $fragmentsTries = $fragmentsDuJo->sortBy(fn ($f) => $this->ordreDeCle($f->document_key))->values();

            $n = min($tetes->count(), $fragmentsTries->count());

            for ($i = 0; $i < $n; $i++) {
                $resultats->push($this->verifierPaire($db, $tetes[$i], $fragmentsTries[$i]));
            }

            $ecart = $tetes->count() - $fragmentsTries->count();

            if ($ecart !== 0) {
                $this->warn("JO {$joId} : {$tetes->count()} tête(s) candidate(s) pour {$fragmentsTries->count()} fragment(s) — décalage de {$ecart}, seules les {$n} premières paires sont vérifiées, le reste à traiter individuellement.");
            }
        }

        $this->afficherLeBilan($resultats);
        $this->ecrire((string) $this->option('rapport'), $resultats->values()->all(), 'Rapport complet');

        $plan = $resultats->where('classe', 'fiable')->values()->all();
        $this->ecrire((string) $this->option('plan'), $plan, 'Plan d\'exécution');

        return self::SUCCESS;
    }

    private function ordreDeCle(string $documentKey): int
    {
        return preg_match('/_acte_(\d+)$/', $documentKey, $m) === 1 ? (int) $m[1] : 0;
    }

    private function numeroDActe(string $titre): ?int
    {
        return preg_match('/n[°ºo]\s*(\d+)/ui', $titre, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return array{numero: string, id: string, ordre_affichage: int, contenu: string}|null
     */
    private function dernierArticleNumerique(mixed $db, string $documentId): ?array
    {
        $article = $db->table('articles')
            ->where('document_id', $documentId)
            ->whereRaw("numero_article ~ '^\\d+$'")
            ->whereNull('deleted_at')
            ->orderByRaw('(numero_article)::int DESC')
            ->select(['id', 'numero_article', 'ordre_affichage'])
            ->first();

        if ($article === null) {
            return null;
        }

        $contenu = $db->table('article_versions')
            ->where('article_id', $article->id)
            ->orderBy('created_at')
            ->value('contenu_texte');

        return [
            'numero' => $article->numero_article,
            'id' => $article->id,
            'ordre_affichage' => $article->ordre_affichage,
            'contenu' => (string) $contenu,
        ];
    }

    private function verifierPaire(mixed $db, object $tete, object $fragment): array
    {
        $dernier = $this->dernierArticleNumerique($db, $tete->id);

        if ($dernier === null) {
            return $this->resultat($tete, $fragment, null, 'ecarte', 'la tête candidate n\'a aucun article numérique');
        }

        if (preg_match('/[.;:!]$/u', trim($dernier['contenu'])) === 1) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le dernier article de la tête se termine par une ponctuation forte — pas de troncature détectée, cette paire est probablement fausse');
        }

        $articlesFragment = $db->table('articles')
            ->where('document_id', $fragment->id)
            ->whereNull('deleted_at')
            ->orderBy('ordre_affichage')
            ->get(['id', 'numero_article', 'ordre_affichage']);

        if ($articlesFragment->isEmpty()) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le fragment n\'a aucun article');
        }

        $premier = $articlesFragment->first();

        if (preg_match('/^\d+$/', $premier->numero_article) === 1) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le premier morceau du fragment est déjà numéroté — ce n\'est pas une continuation d\'article');
        }

        $contenuPremier = (string) $db->table('article_versions')
            ->where('article_id', $premier->id)->orderBy('created_at')->value('contenu_texte');

        if (preg_match('/^\p{Ll}/u', ltrim($contenuPremier)) !== 1) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le premier morceau du fragment ne commence pas en minuscule — pas un signe de continuation fiable');
        }

        $suivants = $articlesFragment->skip(1)->filter(fn ($a) => preg_match('/^\d+$/', $a->numero_article) === 1)->values();
        $attendu = (int) $dernier['numero'] + 1;

        foreach ($suivants as $a) {
            if ((int) $a->numero_article !== $attendu) {
                return $this->resultat($tete, $fragment, $dernier, 'ecarte', "la numérotation du fragment ({$a->numero_article}) ne reprend pas à la suite de la tête (attendu {$attendu})");
            }
            $attendu++;
        }

        if ($tete->curation_status === 'published') {
            $signal = 'PUBLIÉ — article tronqué visible du public dès maintenant';
        } else {
            $signal = 'brouillon';
        }

        return $this->resultat($tete, $fragment, $dernier, 'fiable', "tous les contrôles structurels passent ({$signal})");
    }

    private function resultat(object $tete, object $fragment, ?array $dernier, string $classe, string $raison): array
    {
        return [
            'tete_id' => $tete->id,
            'tete_titre' => $tete->titre_officiel,
            'tete_statut' => $tete->curation_status,
            'fragment_id' => $fragment->id,
            'dernier_article_tete' => $dernier['numero'] ?? null,
            'classe' => $classe,
            'raison' => $raison,
        ];
    }

    private function afficherLeBilan($resultats): void
    {
        $parClasse = $resultats->countBy('classe');
        $publies = $resultats->where('classe', 'fiable')->where('tete_statut', 'published')->count();

        $this->newLine();
        $this->table(['Classe', 'Paires'], [
            ['fiable', $parClasse->get('fiable', 0)],
            ['ecarte', $parClasse->get('ecarte', 0)],
        ]);

        if ($publies > 0) {
            $this->newLine();
            $this->warn("{$publies} paire(s) fiable(s) concernent un document DÉJÀ PUBLIÉ — visible du public avec un article tronqué dès maintenant.");
        }

        $ecartes = $resultats->where('classe', 'ecarte');

        if ($ecartes->isNotEmpty()) {
            $this->newLine();
            $this->line('Motifs des paires écartées :');

            foreach ($ecartes->countBy('raison') as $raison => $n) {
                $this->line("  · {$n}× {$raison}");
            }
        }
    }

    private function ecrire(string $chemin, array $donnees, string $libelle): void
    {
        if ($chemin === '') {
            return;
        }

        file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->info("{$libelle} : {$chemin} (".count($donnees).' entrée(s))');
    }

    private function executer(): int
    {
        $chemin = (string) $this->option('plan');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --plan obligatoire avec --execute : chemin d\'un plan JSON lisible (généré par un passage préalable sans --execute).');

            return self::FAILURE;
        }

        $plan = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($plan) || $plan === []) {
            $this->error('Le plan est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $connexion = (string) $this->option('connection');

        if ($connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-fusion-fragments-'.now()->format('Ymd-His').'.json'));

        // Passe de lecture d'abord : le retour arrière est écrit AVANT toute
        // écriture, pour couvrir le lot même si la transaction échoue en cours.
        $paires = [];
        $retourArriere = [];

        foreach ($plan as $paire) {
            $teteId = $paire['tete_id'] ?? null;
            $fragmentId = $paire['fragment_id'] ?? null;

            if (! is_string($teteId) || ! is_string($fragmentId)) {
                continue;
            }

            $dernier = $this->dernierArticleNumerique($db, $teteId);
            $articlesFragment = $db->table('articles')
                ->where('document_id', $fragmentId)->whereNull('deleted_at')
                ->orderBy('ordre_affichage')->get(['id', 'numero_article', 'ordre_affichage']);

            if ($dernier === null || $articlesFragment->isEmpty()) {
                $this->warn("Paire ignorée (état changé depuis le diagnostic) : {$teteId}");

                continue;
            }

            $paires[] = ['tete_id' => $teteId, 'fragment_id' => $fragmentId, 'dernier' => $dernier, 'articles_fragment' => $articlesFragment];
            $retourArriere[] = [
                'tete_id' => $teteId,
                'article_complete_id' => $dernier['id'],
                'contenu_original' => $dernier['contenu'],
                'fragment_id' => $fragmentId,
            ];
        }

        if ($paires === []) {
            $this->warn('Aucune paire exécutable (toutes ignorées).');

            return self::SUCCESS;
        }

        file_put_contents($fichierRetour, json_encode($retourArriere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;

        $db->transaction(function () use ($db, $paires, &$touchees) {
            foreach ($paires as $p) {
                $teteId = $p['tete_id'];
                $fragmentId = $p['fragment_id'];
                $dernier = $p['dernier'];
                $articlesFragment = $p['articles_fragment'];

                $premier = $articlesFragment->first();
                $contenuPremier = (string) $db->table('article_versions')
                    ->where('article_id', $premier->id)->orderBy('created_at')->value('contenu_texte');

                // 1. Complète l'article tronqué de la tête.
                $db->table('article_versions')->where('article_id', $dernier['id'])
                    ->update(['contenu_texte' => rtrim($dernier['contenu'])."\n".ltrim($contenuPremier), 'updated_at' => now()]);

                // 2. Rapatrie les articles suivants du fragment sous la tête.
                $ordre = $dernier['ordre_affichage'];

                foreach ($articlesFragment->skip(1) as $a) {
                    $ordre++;
                    $contenu = (string) $db->table('article_versions')
                        ->where('article_id', $a->id)->orderBy('created_at')->value('contenu_texte');

                    $nouvelId = (string) Str::uuid();
                    $db->table('articles')->insert([
                        'id' => $nouvelId, 'document_id' => $teteId, 'parent_node_id' => null,
                        'numero_article' => $a->numero_article, 'ordre_affichage' => $ordre,
                        'validation_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $db->table('article_versions')->insert([
                        'id' => (string) Str::uuid(), 'article_id' => $nouvelId,
                        'contenu_texte' => $contenu, 'validity_period' => '['.now()->toDateString().',)',
                        'validation_status' => 'pending', 'is_verified' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                // 3. Retire le document fragment, devenu vide de sens.
                $db->table('articles')->where('document_id', $fragmentId)->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);
                $db->table('legal_documents')->where('id', $fragmentId)->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);

                $touchees++;
            }
        });

        $this->info("{$touchees} paire(s) fusionnée(s).");

        return self::SUCCESS;
    }
}
