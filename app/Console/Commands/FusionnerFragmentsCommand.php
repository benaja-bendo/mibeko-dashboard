<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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
 * DEUX MODES D'APPARIEMENT, jamais simultanés :
 *
 * 1. **Par intitulé partagé** (`--titre-fragment`) : le gros cluster minier,
 *    où un JO produit un lot de fragments portant tous la même clause coupée
 *    en guise de titre, numérotés `..._acte_1`, `..._acte_2`, … dans
 *    `document_key`, dans le même ordre que les arrêtés qu'ils complètent.
 *    Têtes et fragments sont triés puis appariés par rang.
 * 2. **Par adjacence dans le JO** (mode par défaut, sans `--titre-fragment`) :
 *    la coupure produit un fragment qui suit IMMÉDIATEMENT sa tête dans
 *    l'ordre de découpage du JO (`document_key`, suffixe `_acte_N`), que les
 *    fragments partagent un intitulé ou non. C'est le seul mode qui atteint
 *    les fragments à titre unique — la majorité d'entre eux — que le mode 1
 *    ne voit pas, faute de lot à regrouper. L'adjacence est stricte : tout
 *    document intercalé entre une tête tronquée et un fragment annule
 *    l'appariement plutôt que de le deviner.
 *
 * Dans les deux modes, chaque paire est vérifiée structurellement avant d'être
 * proposée (jamais supposée sur le seul rang ni sur la seule adjacence) :
 *   - le dernier article numérique de la tête ne se termine PAS par une
 *     ponctuation forte (signe de troncature) ;
 *   - le premier morceau du fragment n'est pas numéroté (typiquement étiqueté
 *     PREAMBULE par erreur) et commence par une minuscule (signe de suite) ;
 *   - les articles numérotés du fragment reprennent exactement à
 *     (dernier numéro de la tête + 1) ;
 *   - aucun numéro d'article rapatrié n'entre en collision avec un article
 *     déjà vivant sous la tête (l'index unique partiel
 *     `uq_articles_document_numero` ferait sinon échouer tout le lot) ;
 *   - la version tronquée ne porte pas de tableau structuré (les bornes de
 *     lignes de `source_locator.tables` deviendraient fausses une fois la
 *     suite concaténée).
 * Toute paire qui échoue un de ces contrôles est écartée de la liste
 * exécutable et signalée pour revue individuelle — jamais devinée.
 *
 * CHEMIN D'ÉCRITURE : Eloquent, jamais le query builder. Écrire en SQL brut
 * sur `article_versions` laisserait le corpus incohérent de quatre façons
 * (vérifiées le 10/08/2026, avant tout passage en production) : l'embedding
 * resterait celui du texte tronqué et `mibeko:process-rag` ne le rattraperait
 * jamais (il ne sélectionne que `whereNull('embedding')`) ; aucune version
 * d'article ni aucun audit owen-it ne serait produit ; le cache de réponses de
 * l'assistant continuerait de servir du texte tronqué faute de
 * `CorpusVersion::bump()` ; et `SyncController`, qui pousse aux mobiles sur
 * `updated_at`, enverrait les articles rapatriés sans l'article corrigé — un
 * document incohérent hors ligne.
 *
 * Passer par les modèles règle DEUX de ces quatre points sur la cible :
 * l'embedding remis à NULL et la chaîne `$touches` écrivent bien sur la
 * connexion passée en `--connection`. Les deux autres NON, et il faut le
 * savoir avant d'exécuter (mesuré le 10/08/2026) : la commande écrit sur
 * `pgsql_prod_rw` pendant que l'application tourne sur le `.env` LOCAL —
 * `.env` ne doit jamais être basculé vers la prod (CLAUDE.md), cela
 * redirigerait scheduler, jobs et serveurs MCP. Or `CorpusVersion::bump()`
 * passe par `Cache::forever` sur le store `database`, donc la table `cache`
 * de la connexion PAR DÉFAUT ; et owen-it résout la connexion de ses lignes
 * `audits` sur `config('audit.drivers.database.connection')`, à `null` ici,
 * donc là aussi la connexion par défaut. Concrètement : **le cache de
 * l'assistant en production n'est PAS invalidé et l'audit prod reste vide** —
 * ils atterrissent dans la base de dev locale. La commande le rappelle
 * explicitement en fin d'exécution ; l'invalidation du cache prod est une
 * étape manuelle, et la traçabilité repose sur le fichier de retour arrière
 * et sur l'historique des versions d'articles, tous deux bien écrits en prod.
 *
 *   php artisan mibeko:fusionner-fragments --connection=pgsql_prod_ro \
 *       --titre-fragment="arrêté pourra faire l’objet d’une suspension ou d’un" \
 *       --rapport=storage/app/rapport.json --plan=storage/app/plan.json
 *   php artisan mibeko:fusionner-fragments --connection=pgsql_prod_ro \
 *       --jo=<uuid> --rapport=storage/app/rapport.json --plan=storage/app/plan.json
 *   php artisan mibeko:fusionner-fragments --plan=storage/app/plan.json --connection=pgsql_prod_rw --execute
 */
class FusionnerFragmentsCommand extends Command
{
    protected $signature = 'mibeko:fusionner-fragments
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en diagnostic, pgsql_prod_rw pour écrire)}
        {--titre-fragment= : Filtre facultatif : intitulé exact partagé par un lot de fragments. Sans lui, appariement par adjacence dans le JO.}
        {--jo= : Filtre facultatif : restreint le diagnostic à ce Journal officiel}
        {--rapport= : Fichier où écrire le rapport complet (paires fiables et écartées)}
        {--plan= : Fichier où écrire le plan d\'exécution (paires fiables uniquement)}
        {--execute : Applique réellement le plan. Sans cette option, diagnostic seul.}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}';

    protected $description = 'Recolle les articles tronqués sur les documents PUBLIÉS d\'un JO (lecture seule par défaut).';

    public function handle(): int
    {
        if ((bool) $this->option('execute')) {
            return $this->executer();
        }

        return $this->diagnostiquer();
    }

    private function diagnostiquer(): int
    {
        $db = DB::connection((string) $this->option('connection'));
        $titre = (string) $this->option('titre-fragment');

        $resultats = $titre !== ''
            ? $this->apparierParTitrePartage($db, $titre)
            : $this->apparierParAdjacence($db);

        if ($resultats->isEmpty()) {
            $this->warn('Aucun fragment candidat trouvé.');

            return self::SUCCESS;
        }

        $this->afficherLeBilan($resultats);
        $this->ecrire((string) $this->option('rapport'), $resultats->values()->all(), 'Rapport complet');

        $plan = $resultats->where('classe', 'fiable')->values()->all();
        $this->ecrire((string) $this->option('plan'), $plan, 'Plan d\'exécution');

        return self::SUCCESS;
    }

    /**
     * Mode 1 — lot de fragments partageant un intitulé, appariés par rang.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function apparierParTitrePartage(Connection $db, string $titre): Collection
    {
        $fragments = $this->documentsFiltres($db)
            ->where('titre_officiel', $titre)
            ->orderBy('official_journal_id')
            ->orderBy('document_key')
            ->get();

        if ($fragments->isEmpty()) {
            $this->warn('Aucun document ne porte cet intitulé.');

            return collect();
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
            $candidats = $this->documentsFiltres($db)
                ->where('official_journal_id', $joId)
                ->where('id', '!=', $fragmentsDuJo->first()->id)
                ->whereRaw('titre_officiel != ?', [$titre])
                ->get()
                ->filter(fn ($t) => $this->numeroDActe((string) $t->titre_officiel) !== null)
                ->sortBy(fn ($t) => $this->numeroDActe((string) $t->titre_officiel))
                ->values();

            $tetes = collect();

            foreach ($candidats as $candidat) {
                $dernier = $this->dernierArticleNumerique($db, $candidat->id);

                // Les deux motifs d'exclusion sont vérifiés ICI, avant tout
                // appariement — pas dans verifierPaire() — précisément pour
                // qu'un candidat écarté ne consomme jamais de rang et ne
                // décale donc jamais l'appariement des candidats suivants.
                if ($dernier === null) {
                    $resultats->push($this->resultat($candidat, $this->fragmentAbsent(), null, 'ecarte', 'aucun article numérique — structure trop différente pour appartenir à ce cluster'));

                    continue;
                }

                if ($this->seTerminePar($dernier['contenu'])) {
                    $resultats->push($this->resultat($candidat, $this->fragmentAbsent(), $dernier, 'ecarte', 'le dernier article de la tête se termine par une ponctuation forte — pas de troncature détectée, ce document n\'appartient probablement pas au cluster'));

                    continue;
                }

                $tetes->push($candidat);
            }

            $fragmentsTries = $fragmentsDuJo->sortBy(fn ($f) => $this->ordreDeCle((string) $f->document_key))->values();

            $n = min($tetes->count(), $fragmentsTries->count());

            for ($i = 0; $i < $n; $i++) {
                $resultats->push($this->verifierPaire($db, $tetes[$i], $fragmentsTries[$i]));
            }

            $ecart = $tetes->count() - $fragmentsTries->count();

            if ($ecart !== 0) {
                $this->warn("JO {$joId} : {$tetes->count()} tête(s) candidate(s) pour {$fragmentsTries->count()} fragment(s) — décalage de {$ecart}, seules les {$n} premières paires sont vérifiées, le reste à traiter individuellement.");
            }
        }

        return $resultats;
    }

    /**
     * Mode 2 — appariement par adjacence dans l'ordre de découpage du JO.
     *
     * Ne dépend d'AUCUN titre partagé : c'est la seule façon d'atteindre les
     * fragments isolés, dont l'intitulé est unique parce que la coupure est
     * tombée sur une phrase différente. La coupure produit toujours deux actes
     * consécutifs (la tête, puis sa suite), donc le fragment suit immédiatement
     * sa tête dans `document_key` ; tout document intercalé rompt la chaîne et
     * remet le compteur à zéro plutôt que de tenter un appariement à distance.
     *
     * Un document reconnu comme fragment n'est jamais réutilisé comme tête :
     * il est destiné à la corbeille, il ne peut pas absorber un autre fragment.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function apparierParAdjacence(Connection $db): Collection
    {
        $documents = $this->documentsFiltres($db)
            ->whereNotNull('official_journal_id')
            ->get();

        if ($documents->isEmpty()) {
            return collect();
        }

        $resultats = collect();

        foreach ($documents->groupBy('official_journal_id') as $documentsDuJo) {
            $ordonnes = $documentsDuJo
                ->sortBy([
                    fn ($a, $b) => $this->ordreDeCle((string) $a->document_key) <=> $this->ordreDeCle((string) $b->document_key),
                    fn ($a, $b) => strcmp((string) $a->document_key, (string) $b->document_key),
                ])
                ->values();

            $teteEnAttente = null;

            foreach ($ordonnes as $document) {
                if (! $this->estUnFragment($db, $document->id, (string) $document->titre_officiel)) {
                    $teteEnAttente = $this->estUneTeteTronquee($db, $document->id) ? $document : null;

                    continue;
                }

                $resultats->push($teteEnAttente === null
                    ? $this->resultat($this->teteAbsente(), $document, null, 'ecarte', 'suite d\'article détectée mais aucun acte tronqué ne la précède immédiatement dans ce JO — rattachement à faire à la main')
                    : $this->verifierPaire($db, $teteEnAttente, $document));

                $teteEnAttente = null;
            }
        }

        return $resultats;
    }

    /**
     * Requête de base sur les documents vivants, filtre `--jo` appliqué.
     */
    private function documentsFiltres(Connection $db): Builder
    {
        $jo = (string) $this->option('jo');

        // Même précaution que sur les identifiants du plan : `official_journal_id`
        // est de type uuid, donc une faute de frappe produirait une SQLSTATE
        // 22P02 brute au lieu d'un filtre vide et d'un bilan lisible.
        if ($jo !== '' && ! Str::isUuid($jo)) {
            $this->warn("--jo n'est pas un UUID valide : filtre ignoré.");
            $jo = '';
        }

        return $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->when($jo !== '', fn ($q) => $q->where('official_journal_id', $jo))
            ->select(['id', 'titre_officiel', 'curation_status', 'document_key', 'official_journal_id']);
    }

    /**
     * Un fragment est un document dont le PREMIER morceau n'est pas numéroté et
     * commence en minuscule : la suite d'une phrase coupée, jamais un début
     * d'acte (un acte commence par un visa ou un « Article premier »).
     */
    private function estUnFragment(Connection $db, string $documentId, ?string $titre = null): bool
    {
        // Le titre d'un fragment est, par construction, le morceau de phrase sur
        // lequel le découpage a coupé : jamais un intitulé d'acte. Sans ce
        // contrôle, la commande prend pour des fragments les actes RÉELS dont le
        // seul titre est tronqué — leur préambule commence lui aussi en
        // minuscule, puisqu'il porte la suite de l'intitulé. Mesuré le
        // 10/08/2026 en diagnostic global : 125 des 186 paires écartées étaient
        // de tels actes, qui noyaient les 61 fragments véritables.
        if ($titre !== null && $this->ressembleAUnIntituleDActe($titre)) {
            return false;
        }

        $premier = $this->premierMorceau($db, $documentId);

        return $premier !== null
            && preg_match('/^\d+$/', (string) $premier->numero_article) !== 1
            && preg_match('/^\p{Ll}/u', ltrim((string) $premier->contenu_texte)) === 1;
    }

    /**
     * Un intitulé d'acte ouvre sur son genre PUIS porte son identité : un
     * numéro, une date, ou une qualification (« organique », « constitutionnelle »).
     * Une clause coupée en milieu de phrase enchaîne sur autre chose
     * (« arrêté ainsi que l'ensemble de la réglementation »).
     */
    private function ressembleAUnIntituleDActe(string $titre): bool
    {
        return preg_match(
            '/^(?:loi|d[ée]cret|arr[êe]t[ée]|ordonnance|d[ée]cision|d[ée]lib[ée]ration|circulaire|avis|acte|constitution|rectificatif|additif|communiqu[ée])\b[\s,]*(?:n|organique|constitutionnelle|interminist[ée]riel|du\b|\d)/iu',
            trim($titre),
        ) === 1;
    }

    private function estUneTeteTronquee(Connection $db, string $documentId): bool
    {
        $dernier = $this->dernierArticleNumerique($db, $documentId);

        return $dernier !== null && ! $this->seTerminePar($dernier['contenu']);
    }

    private function seTerminePar(string $contenu): bool
    {
        return preg_match('/[.;:!]$/u', trim($contenu)) === 1;
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
     * Version qui fait foi pour un article : celle qui est ouverte
     * (`upper_inf`), à défaut la plus récemment créée. Prendre la plus ancienne
     * ferait lire — et pire, réécrire — une version déjà fermée, donc invisible
     * du public : un correctif appliqué là serait sans effet.
     */
    private function versionQuiFaitFoi(Connection $db, string $articleId): ?object
    {
        return $db->table('article_versions')
            ->where('article_id', $articleId)
            ->orderByRaw('upper_inf(validity_period) DESC, created_at DESC')
            ->select(['id', 'contenu_texte', 'source_locator', 'validity_period'])
            ->first();
    }

    /**
     * Premier morceau d'un document dans l'ordre d'affichage, avec le contenu
     * de la version qui fait foi.
     */
    private function premierMorceau(Connection $db, string $documentId): ?object
    {
        return $db->table('articles as a')
            ->join('article_versions as av', 'av.article_id', '=', 'a.id')
            ->where('a.document_id', $documentId)
            ->whereNull('a.deleted_at')
            ->orderBy('a.ordre_affichage')
            ->orderByRaw('upper_inf(av.validity_period) DESC, av.created_at DESC')
            ->select(['a.id', 'a.numero_article', 'av.contenu_texte'])
            ->first();
    }

    /**
     * @return array{numero: string, id: string, ordre_affichage: int, contenu: string, version_id: string|null, validity_period: string|null, source_locator: array<string, mixed>}|null
     */
    private function dernierArticleNumerique(Connection $db, string $documentId): ?array
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

        $version = $this->versionQuiFaitFoi($db, $article->id);
        $locator = json_decode((string) ($version?->source_locator ?? '{}'), true);

        return [
            'numero' => $article->numero_article,
            'id' => $article->id,
            'ordre_affichage' => $article->ordre_affichage,
            'contenu' => (string) ($version?->contenu_texte ?? ''),
            'version_id' => $version?->id,
            'validity_period' => $version?->validity_period,
            'source_locator' => is_array($locator) ? $locator : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifierPaire(Connection $db, object $tete, object $fragment): array
    {
        $dernier = $this->dernierArticleNumerique($db, $tete->id);

        if ($dernier === null) {
            return $this->resultat($tete, $fragment, null, 'ecarte', 'la tête candidate n\'a aucun article numérique');
        }

        if ($this->seTerminePar($dernier['contenu'])) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le dernier article de la tête se termine par une ponctuation forte — pas de troncature détectée, cette paire est probablement fausse');
        }

        // Concaténer déplacerait toutes les lignes du texte : les bornes
        // `line_start`/`line_end` des tableaux canoniques deviendraient fausses
        // et la surface de lecture rendrait un tableau décalé. Cas jamais vu
        // sur une phrase coupée en plein milieu, mais qui mérite un humain.
        if (($dernier['source_locator']['tables'] ?? []) !== []) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'la version tronquée porte un tableau structuré — les bornes de lignes deviendraient fausses après concaténation');
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

        if (preg_match('/^\d+$/', (string) $premier->numero_article) === 1) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le premier morceau du fragment est déjà numéroté — ce n\'est pas une continuation d\'article');
        }

        $contenuPremier = (string) ($this->versionQuiFaitFoi($db, $premier->id)?->contenu_texte ?? '');

        if (preg_match('/^\p{Ll}/u', ltrim($contenuPremier)) !== 1) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'le premier morceau du fragment ne commence pas en minuscule — pas un signe de continuation fiable');
        }

        $aRapatrier = $articlesFragment->skip(1)->values();
        $suivants = $aRapatrier->filter(fn ($a) => preg_match('/^\d+$/', (string) $a->numero_article) === 1)->values();
        $attendu = (int) $dernier['numero'] + 1;

        foreach ($suivants as $a) {
            if ((int) $a->numero_article !== $attendu) {
                return $this->resultat($tete, $fragment, $dernier, 'ecarte', "la numérotation du fragment ({$a->numero_article}) ne reprend pas à la suite de la tête (attendu {$attendu})");
            }
            $attendu++;
        }

        // Contrôle avant écriture : `uq_articles_document_numero` est un index
        // unique partiel sur (document_id, numero_article) des articles vivants.
        // Une collision ferait échouer l'INSERT et annulerait la transaction —
        // donc TOUT le lot, y compris les paires saines déjà fusionnées.
        $collisions = $db->table('articles')
            ->where('document_id', $tete->id)
            ->whereNull('deleted_at')
            ->whereIn('numero_article', $aRapatrier->pluck('numero_article')->all())
            ->pluck('numero_article');

        if ($collisions->isNotEmpty()) {
            return $this->resultat($tete, $fragment, $dernier, 'ecarte', 'la tête porte déjà un article portant le(s) numéro(s) '.$collisions->implode(', ').' — le rapatriement entrerait en collision avec un article existant');
        }

        $signal = $tete->curation_status === 'published'
            ? 'PUBLIÉ — article tronqué visible du public dès maintenant'
            : 'brouillon';

        return $this->resultat($tete, $fragment, $dernier, 'fiable', "tous les contrôles structurels passent ({$signal})");
    }

    /**
     * @param  array<string, mixed>|null  $dernier
     * @return array<string, mixed>
     */
    private function resultat(object $tete, object $fragment, ?array $dernier, string $classe, string $raison): array
    {
        return [
            'tete_id' => $tete->id,
            'tete_titre' => $tete->titre_officiel ?? null,
            'tete_statut' => $tete->curation_status ?? null,
            'fragment_id' => $fragment->id,
            'fragment_titre' => $fragment->titre_officiel ?? null,
            'fragment_statut' => $fragment->curation_status ?? null,
            'dernier_article_tete' => $dernier['numero'] ?? null,
            'classe' => $classe,
            'raison' => $raison,
        ];
    }

    private function teteAbsente(): object
    {
        return (object) ['id' => null, 'titre_officiel' => null, 'curation_status' => null];
    }

    private function fragmentAbsent(): object
    {
        return (object) ['id' => null, 'titre_officiel' => null, 'curation_status' => null];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $resultats
     */
    private function afficherLeBilan(Collection $resultats): void
    {
        $parClasse = $resultats->countBy('classe');
        $fiables = $resultats->where('classe', 'fiable');
        $publies = $fiables->where('tete_statut', 'published')->count();
        $fragmentsPublies = $fiables->where('fragment_statut', 'published')->count();

        $this->newLine();
        $this->table(['Classe', 'Paires'], [
            ['fiable', $parClasse->get('fiable', 0)],
            ['ecarte', $parClasse->get('ecarte', 0)],
        ]);

        if ($publies > 0) {
            $this->newLine();
            $this->warn("{$publies} paire(s) fiable(s) concernent un document DÉJÀ PUBLIÉ — visible du public avec un article tronqué dès maintenant.");
        }

        if ($fragmentsPublies > 0) {
            $this->warn("{$fragmentsPublies} fragment(s) à mettre en corbeille sont eux-mêmes PUBLIÉS — leur retrait soustrait du contenu public (leur texte est repris sous la tête).");
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

    /**
     * @param  array<int, mixed>  $donnees
     */
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

            // Identifiants filtrés avant toute requête : les colonnes visées
            // sont des uuid, une chaîne quelconque ferait échouer le SQL et
            // emporterait le lot entier au lieu de la seule ligne fautive.
            if (! is_string($teteId) || ! is_string($fragmentId)
                || ! Str::isUuid($teteId) || ! Str::isUuid($fragmentId)) {
                continue;
            }

            $tete = $this->documentVivant($db, $teteId);
            $fragment = $this->documentVivant($db, $fragmentId);

            if ($tete === null || $fragment === null) {
                $this->warn("Paire ignorée (document disparu depuis le diagnostic) : {$teteId}");

                continue;
            }

            // Le plan peut avoir vieilli entre le diagnostic et l'autorisation
            // humaine : on refait la vérification complète plutôt que de faire
            // confiance à un fichier. Une paire devenue douteuse est écartée,
            // jamais fusionnée sur la foi du diagnostic d'hier.
            $verification = $this->verifierPaire($db, $tete, $fragment);

            if ($verification['classe'] !== 'fiable') {
                $this->warn("Paire écartée à l'exécution (état changé depuis le diagnostic) : {$teteId} — {$verification['raison']}");

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
                // La fusion ne réécrit plus la version en place : elle la ferme
                // et en ouvre une neuve. Défaire = supprimer la version ouverte
                // aujourd'hui, rouvrir celle-ci sur sa période d'origine, puis
                // supprimer les articles rapatriés listés ci-dessous.
                'version_fermee_id' => $dernier['version_id'],
                'version_fermee_periode' => $dernier['validity_period'],
                'numeros_rapatries' => $articlesFragment->skip(1)->pluck('numero_article')->values()->all(),
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

        $db->transaction(function () use ($connexion, $paires, &$touchees) {
            foreach ($paires as $p) {
                $this->fusionner($connexion, $p);
                $touchees++;
            }
        });

        $this->info("{$touchees} paire(s) fusionnée(s).");

        // Deux effets attendus n'atteignent PAS une cible distante : le bump de
        // CorpusVersion et les lignes d'audit owen-it partent sur la connexion
        // par défaut de l'application, c'est-à-dire la base LOCALE, jamais
        // celle de `--connection`. Le taire donnerait l'illusion d'une
        // opération complète — l'assistant continuerait de citer le texte
        // tronqué, sans que rien ne le signale.
        if (! in_array((string) $this->option('connection'), ['pgsql', '', 'pgsql_testing'], true)) {
            $this->newLine();
            $this->warn('Deux étapes restent MANUELLES sur la cible :');
            $this->line('  · invalider le cache de réponses de l\'assistant (CorpusVersion) — sinon il sert encore le texte tronqué ;');
            $this->line('  · l\'audit owen-it de ces écritures n\'existe pas sur la cible ; la trace est le fichier de retour arrière ci-dessus et l\'historique des versions d\'articles.');
            $this->line('  · les embeddings des versions corrigées sont à NULL : lancer `mibeko:process-rag` sur la cible.');
        }

        return self::SUCCESS;
    }

    private function documentVivant(Connection $db, string $id): ?object
    {
        return $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->select(['id', 'titre_officiel', 'curation_status', 'document_key', 'official_journal_id'])
            ->first();
    }

    /**
     * Fusionne une paire. Tout passe par Eloquent : les observateurs
     * (invalidation du cache IA) et la chaîne `$touches`
     * (version → article → document) sont le seul moyen de laisser le corpus
     * cohérent pour la recherche sémantique, l'assistant et la synchro mobile.
     *
     * @param  array{tete_id: string, fragment_id: string, dernier: array<string, mixed>, articles_fragment: Collection<int, object>}  $paire
     */
    private function fusionner(string $connexion, array $paire): void
    {
        $articleTronque = Article::on($connexion)->findOrFail($paire['dernier']['id']);
        $articlesFragment = $paire['articles_fragment'];

        // 1. Complète l'article tronqué de la tête, versionné comme le ferait
        //    PATCH /api/v1/articles/{id} — jamais un UPDATE en place.
        $suite = $this->versionEloquentQuiFaitFoi($connexion, (string) $articlesFragment->first()->id);
        $this->reecrireLeContenu(
            $articleTronque,
            rtrim((string) $paire['dernier']['contenu'])."\n".ltrim((string) ($suite?->contenu_texte ?? '')),
        );

        // 2. Rapatrie les articles suivants du fragment sous la tête, avec leur
        //    `source_locator` : c'est lui qui porte la page source, donc la
        //    citabilité — la perdre reviendrait à rapatrier du texte non sourcé.
        $ordre = (int) $articleTronque->ordre_affichage;

        foreach ($articlesFragment->skip(1) as $a) {
            $ordre++;
            $version = $this->versionEloquentQuiFaitFoi($connexion, (string) $a->id);

            $nouveau = Article::on($connexion)->create([
                'document_id' => $paire['tete_id'],
                'parent_node_id' => null,
                'numero_article' => $a->numero_article,
                'ordre_affichage' => $ordre,
                'validation_status' => 'pending',
            ]);

            $nouveau->versions()->create([
                'contenu_texte' => (string) ($version?->contenu_texte ?? ''),
                'source_locator' => $version?->source_locator ?? [],
                'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                'validation_status' => 'pending',
                'is_verified' => false,
                // Explicite plutôt que par défaut : le cron `mibeko:process-rag`
                // ne rattrape que `embedding IS NULL`.
                'embedding' => null,
            ]);
        }

        // 3. Retire le document fragment, devenu vide de sens. Le modèle cascade
        //    le soft-delete sur ses articles et invalide le cache de l'assistant.
        LegalDocument::on($connexion)->findOrFail($paire['fragment_id'])->delete();
    }

    private function versionEloquentQuiFaitFoi(string $connexion, string $articleId): ?ArticleVersion
    {
        return ArticleVersion::on($connexion)
            ->where('article_id', $articleId)
            ->orderByRaw('upper_inf(validity_period) DESC, created_at DESC')
            ->first();
    }

    /**
     * Reproduit le versionnage de `ArticleController::update()` : la version
     * courante est fermée à aujourd'hui et une neuve est ouverte, sauf si elle
     * a déjà commencé aujourd'hui (la fermer produirait un daterange vide,
     * rejeté par `chk_article_versions_validity_not_empty`) — auquel cas elle
     * est réécrite sur place. L'embedding est remis à NULL dans les deux cas :
     * l'observateur saute la génération par défaut (`$shouldSkipEmbeddings`),
     * et sans NULL explicite le vecteur du texte TRONQUÉ survivrait pour
     * toujours à la correction, `mibeko:process-rag` ne sélectionnant que
     * `whereNull('embedding')`.
     */
    private function reecrireLeContenu(Article $article, string $contenu): void
    {
        $aujourdhui = now()->toDateString();
        $connexion = (string) $article->getConnectionName();

        $chevauchante = ArticleVersion::on($connexion)
            ->where('article_id', $article->id)
            ->whereRaw('validity_period && daterange(?::date, null)', [$aujourdhui])
            ->first();

        if ($chevauchante !== null) {
            $ouverteAujourdhui = ArticleVersion::on($connexion)
                ->where('id', $chevauchante->id)
                ->whereRaw('lower(validity_period) = ?::date', [$aujourdhui])
                ->exists();

            if ($ouverteAujourdhui) {
                $chevauchante->update(['contenu_texte' => $contenu, 'embedding' => null]);

                return;
            }

            // Binding paramétré : jamais de date interpolée dans le SQL brut.
            DB::connection($connexion)->update(
                'UPDATE article_versions
                 SET validity_period = daterange(lower(validity_period), ?::date)
                 WHERE id = ?',
                [$aujourdhui, $chevauchante->id]
            );
        }

        $article->versions()->create([
            'contenu_texte' => $contenu,
            'source_locator' => $chevauchante?->source_locator ?? [],
            'validity_period' => ArticleVersion::makeValidityPeriod($aujourdhui),
            'validation_status' => 'pending',
            'is_verified' => false,
            'embedding' => null,
        ]);
    }
}
