<?php

namespace App\Traits;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait SearchesArticles
{
    /**
     * Recherche d'articles pour l'IA (agent) et le serveur MCP.
     *
     * Adaptateur fin vers le moteur hybride unique `lexicalArticleContext`
     * (full-text + filets trigram & sémantique, parseur « article N », stop-words
     * structurels, embedding mis en cache). On ne réexpose que les champs utiles
     * à l'outil et aux citations, pour ne pas gonfler le contexte du modèle.
     *
     * @param  array<int, string>|null  $documentIds  Restreint la recherche à ces documents (références épinglées).
     * @return array<int, array<string, mixed>>
     */
    protected function searchArticles(string $query, int $limit = 5, ?string $documentType = null, ?string $documentTitle = null, ?array $documentIds = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        $filters = array_filter([
            'type_like' => $documentType,
            'document_title' => $documentTitle,
            'document_ids' => $documentIds,
        ]);

        return array_map(fn (array $row): array => [
            'id' => $row['id'],
            'number' => $row['number'],
            'order' => $row['order'],
            'content' => $row['content'],
            'document_id' => $row['document_id'],
            'document_title' => $row['document_title'],
            'document_type' => $row['document_type'],
            'node_title' => $row['node_title'],
            'breadcrumb' => $row['breadcrumb'],
            'validation_status' => $row['validation_status'],
            'score' => $row['score'],
        ], $this->lexicalArticleContext($query, $filters, max(1, min(10, $limit))));
    }

    /**
     * Base query for validated, published articles with their full metadata.
     *
     * Shared by the lexical library search, the on-demand AI synthesis context
     * and single-article lookups. Carries no scoring so callers add their own
     * ranking, filtering and pagination.
     */
    protected function baseArticleQuery(): Builder
    {
        return DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
            ->leftJoin('institutions as i', 'ld.institution_id', '=', 'i.id')
            ->leftJoin('official_journals as oj', 'ld.official_journal_id', '=', 'oj.id')
            // La publication du document EST la garde éditoriale : c'est un acte
            // humain explicite, tracé et réversible (cf. LegalDocument::guardUnpublishing).
            // Exiger EN PLUS un `validated` article par article créait une seconde
            // barrière que rien n'alimente — le pipeline Python écrit `pending`
            // (structurer.py, journals.py) — et qui rendait la recherche aveugle
            // sur la quasi-totalité du corpus publié. Seul `error` reste exclu :
            // il signale un article dont le contenu est su fautif.
            ->where('av.validation_status', '!=', 'error')
            // Seule la version ACTIVE d'un article est citable : une correction de
            // contenu (PATCH /articles/{id}) ferme l'ancienne version au lieu de la
            // supprimer (historique, cf. Article::activeVersion). Sans ce filtre, un
            // texte déjà remplacé restait trouvable indéfiniment aux côtés du texte
            // à jour — un utilisateur pouvait citer un article déjà amendé.
            ->whereRaw('upper_inf(av.validity_period)')
            ->whereNull('a.deleted_at')
            ->whereNull('ld.deleted_at')
            ->whereNull('sn.deleted_at')
            ->where('ld.curation_status', 'published')
            ->select([
                'a.id as article_id',
                'a.numero_article',
                'a.ordre_affichage',
                'av.contenu_texte',
                'av.validation_status',
                'ld.id as document_id',
                'ld.slug as document_slug',
                'ld.titre_officiel as document_title',
                // Objet dérivé du corps de l'acte : sur un acte en abrégé, le
                // titre ne dit que « Décret n° 2025-240 du 20 juin 2025. » et
                // le fil d'Ariane du résultat de recherche n'apprend rien.
                'ld.libelle_descriptif as document_descriptive_label',
                'ld.legal_scope',
                'ld.date_publication',
                'ld.institution_id',
                'ld.official_journal_id',
                'dt.code as document_type_code',
                'dt.nom as type_name',
                'sn.titre as node_title',
                'i.nom as institution_name',
                'oj.title as official_journal_title',
                'oj.publication_date as official_journal_date',
            ])
            // Le suffixe est conservé dans `number` pour l'adressage exact,
            // mais signalé explicitement aux clients au lieu d'être masqué par
            // un DISTINCT qui ferait disparaître une occurrence du corpus.
            ->selectRaw("regexp_replace(a.numero_article, '_doublon(_[0-9]+)?$', '', 'i') as canonical_article_number")
            ->selectRaw("(a.numero_article ~* '_doublon(_[0-9]+)?$') as has_duplicate_suffix");
    }

    /**
     * Apply the optional server-side filters to an article query.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyArticleFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['type'])) {
            $query->where('ld.type_code', $filters['type']);
        }
        // Variante tolérante (ILIKE) du filtre de type, pour l'outil IA/MCP qui
        // reçoit un code de type approximatif du modèle (« code », « Loi »…).
        if (! empty($filters['type_like'])) {
            $query->where('ld.type_code', 'ILIKE', '%'.$filters['type_like'].'%');
        }
        // Filtre par titre (ILIKE) : l'IA peut cibler « Code du travail » sans
        // connaître l'identifiant du document.
        if (! empty($filters['document_title'])) {
            $query->where('ld.titre_officiel', 'ILIKE', '%'.$filters['document_title'].'%');
        }
        // Périmètre multi-documents : références « @ » épinglées par l'utilisateur.
        if (! empty($filters['document_ids'])) {
            $query->whereIn('ld.id', $filters['document_ids']);
        }
        if (! empty($filters['institution_id'])) {
            $query->where('ld.institution_id', $filters['institution_id']);
        }
        if (! empty($filters['legal_scope'])) {
            $query->where('ld.legal_scope', $filters['legal_scope']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('ld.date_publication', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('ld.date_publication', '<=', $filters['date_to']);
        }
        if (! empty($filters['document_id'])) {
            $query->where('a.document_id', $filters['document_id']);
        }
        if (! empty($filters['official_journal_id'])) {
            $query->where('ld.official_journal_id', $filters['official_journal_id']);
        }
        if (! empty($filters['tag'])) {
            $query->join('taggables as tgb', function ($join) {
                $join->on('a.id', '=', 'tgb.taggable_id')
                    ->where('tgb.taggable_type', '=', 'App\Models\Article');
            })
                ->join('tags as t', 'tgb.tag_id', '=', 't.id')
                ->where('t.slug', $filters['tag']);
        }
    }

    /**
     * Apply hybrid PostgreSQL ranking to an article query.
     *
     * Le lexical (full-text `ts_rank`) reste primaire et déterministe ; deux
     * filets de rappel le complètent en OR, à poids décroissant :
     *  - trigram (`%>>` / `strict_word_similarity` via l'index GIN
     *    `f_unaccent(contenu_texte)`) pour les variantes morphologiques que le
     *    stemmer français n'unifie pas (dot ↔ dotal) et les fautes de frappe ;
     *  - sémantique (plus proches voisins par distance cosinus sur
     *    `av.embedding`, bornés à `semanticTopK` candidats — voir
     *    {@see semanticCandidateIds}) pour le rappel conceptuel
     *    (« héritage » ↔ « succession »).
     * Le filet sémantique se dégrade gracieusement : si l'embedding de la
     * requête ne peut être généré, la recherche reste purement lexicale.
     *
     * Le filet sémantique n'est PAS un seuil de distance dans le WHERE : mesuré
     * sur le corpus prod, la distance maximale entre deux articles quelconques
     * est d'environ 0.30, bien en-deçà de tout seuil de similarité raisonnable
     * (l'ancien seuil, 0.4, laissait donc passer la quasi-totalité du corpus
     * embeddé pour n'importe quelle requête — jusqu'à 98 % des articles pour
     * une chaîne sans aucun rapport avec le corpus). Le nombre de candidats
     * sémantiques est borné en amont ({@see semanticCandidateIds}), pas leur
     * distance ; ce terme ne fait plus qu'affiner le classement des candidats
     * déjà retenus par ailleurs.
     */
    protected function applyLexicalScoring(Builder $query, string $search, bool $withSemantic = true): void
    {
        // Sépare une éventuelle référence « article N » du reste thématique.
        // Le mot « article » est structurel (présent dans presque tous les
        // textes) : le garder dans la recherche full-text noierait les vrais
        // résultats. On le retire du texte interrogé et on cible le numéro.
        [$articleNum, $topical] = $this->parseArticleQuery($search);

        $orTsQuery = $this->formatTsQuery($topical);
        $hasText = $topical !== '';
        // Les filets approximatifs n'ont de sens que sur un texte d'au moins 3
        // caractères : en deçà, similarité et embedding deviennent du bruit.
        $useApprox = $hasText && mb_strlen($topical) >= 3;

        // Embedding de la requête pour le filet sémantique (mis en cache : une
        // même requête ne le régénère pas). Dégradation gracieuse en cas d'échec.
        $embeddingString = null;
        if ($useApprox && $withSemantic) {
            try {
                $embedding = Str::of($topical)->toEmbeddings(cache: true);
                if (! empty($embedding)) {
                    $embeddingString = '['.implode(',', $embedding).']';
                }
            } catch (\Throwable $e) {
                Log::warning('Filet sémantique de la recherche bibliothèque indisponible : '.$e->getMessage());
            }
        }

        // Candidats sémantiques bornés en top-K, calculés AVANT d'ajouter les
        // conditions lexicales : le clone ne porte que les filtres déjà présents
        // sur $query (document publié, version active, périmètre demandé…), pas
        // encore le WHERE lexical ni le SELECT de score qu'on construit plus bas.
        $semanticIds = [];
        if ($embeddingString !== null) {
            $semanticIds = $this->semanticCandidateIds($query, $embeddingString);
        }

        // L'opérateur indexable `%>>` lit `pg_trgm.strict_word_similarity_threshold` :
        // on le fixe sur la connexion pour que l'index GIN trigram soit utilisable
        // avec NOTRE seuil (et non le défaut 0.5 qui écarterait « dotal »).
        if ($useApprox) {
            DB::statement("SELECT set_config('pg_trgm.strict_word_similarity_threshold', ?, false)", [(string) $this->fuzzyThreshold]);
        }

        // Le score est composé conditionnellement (et non gardé par un paramètre
        // booléen) : Laravel convertit les bindings booléens en entiers, ce que
        // `CASE WHEN ? THEN` refuserait sous PostgreSQL.
        $scoreExpression = "
            (CASE WHEN ?::text IS NOT NULL AND a.numero_article = ?::text THEN 2.0 ELSE 0.0 END) +
            (CASE WHEN ? != '' AND ld.titre_officiel ILIKE ? THEN 0.5 ELSE 0.0 END) +
            (CASE WHEN ? != '' THEN ts_rank(
                to_tsvector('french', COALESCE(ld.titre_officiel, '') || ' ' || COALESCE(ld.libelle_descriptif, '')),
                to_tsquery('french', ?)
            ) * 1.5 ELSE 0.0 END) +
            (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.4 ELSE 0.0 END) +
            (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, to_tsquery('french', ?)) * 0.2 ELSE 0.0 END)
        ";
        $scoreBindings = [
            $articleNum, $articleNum,
            $topical, "%$topical%",
            $orTsQuery, $orTsQuery,
            $topical, $topical,
            $orTsQuery, $orTsQuery,
        ];

        if ($useApprox) {
            $scoreExpression .= ' + (strict_word_similarity(f_unaccent(?), f_unaccent(av.contenu_texte)) * 0.3)';
            $scoreBindings[] = $topical;
        }

        if ($embeddingString !== null) {
            // Poids faible : filet de dernier recours, sous le lexical et le trigram.
            $scoreExpression .= ' + (COALESCE(1 - (av.embedding <=> ?::vector), 0) * 0.25)';
            $scoreBindings[] = $embeddingString;
        }

        $query->selectRaw("({$scoreExpression}) as total_score", $scoreBindings)
            ->where(function ($q) use ($articleNum, $topical, $orTsQuery, $hasText, $useApprox, $semanticIds) {
                if ($hasText) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$topical]);
                    if ($orTsQuery !== '') {
                        $q->orWhereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$orTsQuery]);
                        $q->orWhereRaw(
                            "to_tsvector('french', COALESCE(ld.titre_officiel, '') || ' ' || COALESCE(ld.libelle_descriptif, '')) @@ to_tsquery('french', ?)",
                            [$orTsQuery],
                        );
                    }
                    $q->orWhere('ld.titre_officiel', 'ILIKE', "%$topical%");
                    // Filet trigram via l'opérateur indexable `%>>` (index GIN
                    // `f_unaccent(contenu_texte)`), seuil = GUC fixé plus haut.
                    if ($useApprox) {
                        $q->orWhereRaw('f_unaccent(av.contenu_texte) %>> f_unaccent(?)', [$topical]);
                    }
                    // Filet sémantique : borné aux `semanticTopK` plus proches
                    // voisins (voir semanticCandidateIds), jamais un seuil de
                    // distance non borné — c'est ce qui faisait remonter jusqu'à
                    // 98 % du corpus embeddé pour n'importe quelle requête.
                    if (! empty($semanticIds)) {
                        $q->orWhereIn('a.id', $semanticIds);
                    }
                    if ($articleNum !== null) {
                        $q->orWhere('a.numero_article', '=', $articleNum);
                    }
                } elseif ($articleNum !== null) {
                    // Requête purement « article N » : on ne renvoie que les
                    // articles portant ce numéro, sans le bruit du full-text.
                    $q->where('a.numero_article', '=', $articleNum);
                }
            });
    }

    /**
     * Plus proches voisins sémantiques d'une requête, bornés à `semanticTopK`.
     *
     * Remplace un seuil de distance cosinus : mesuré sur le corpus prod, la
     * distance maximale entre deux articles quelconques est d'environ 0.30,
     * bien en-deçà de tout seuil de similarité raisonnable — avec ce modèle
     * d'embedding, un seuil ne borne donc RIEN (une requête sans aucun rapport
     * avec le corpus faisait remonter jusqu'à 98 % des articles embeddés). Ce
     * qui borne réellement le bruit, c'est le NOMBRE de candidats retenus,
     * indépendamment de leur distance.
     *
     * `$filteredQuery` doit déjà porter tous les filtres de base (document
     * publié, version active, périmètre demandé…) : le clone hérite du WHERE
     * existant, remplace uniquement la liste SELECT.
     *
     * @return array<int, string> Identifiants d'articles (a.id), au plus `semanticTopK`.
     */
    protected function semanticCandidateIds(Builder $filteredQuery, string $embeddingString): array
    {
        return (clone $filteredQuery)
            ->select('a.id')
            ->whereNotNull('av.embedding')
            ->orderByRaw('av.embedding <=> ?::vector', [$embeddingString])
            ->limit($this->semanticTopK)
            ->get()
            ->pluck('id')
            ->all();
    }

    /**
     * Sépare une requête en (numéro d'article ciblé, reste thématique).
     *
     * Reconnaît « article 45 », « art. 45 bis », « 45 code du travail » ou un
     * simple « 45 ». Le numéro est extrait du texte pour que le mot structurel
     * « article » ne pollue pas la recherche full-text.
     *
     * @return array{0: ?string, 1: string} [numéro|null, texte thématique]
     */
    protected function parseArticleQuery(string $search): array
    {
        $search = trim($search);

        if (preg_match('/\bart(?:icle)?s?\.?\s*(\d+[\w.-]*)/iu', $search, $matches)) {
            $rest = trim(preg_replace('/\bart(?:icle)?s?\.?\s*\d+[\w.-]*/iu', ' ', $search) ?? '');

            return [$matches[1], $rest];
        }

        if (preg_match('/^(\d+[\w.-]*)\s*(.*)$/u', $search, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $search];
    }

    /**
     * Map a raw article row to the API item shape used by the library.
     *
     * @return array<string, mixed>
     */
    protected function mapArticleRow(object $item): array
    {
        // Le libellé descriptif s'AJOUTE au titre officiel dans le fil
        // d'Ariane, il ne s'y substitue pas : « Décret > Décret n° 2025-240 du
        // 20 juin 2025. > Nomination : … ». Sans lui, le maillon du milieu
        // n'apprend rien sur un acte en abrégé ; sans le titre, on présenterait
        // une paraphrase comme l'intitulé officiel du texte.
        $breadcrumb = implode(' > ', array_filter([
            $item->type_name ?? null,
            $item->document_title ?? null,
            $item->document_descriptive_label ?? null,
            $item->node_title ?? null,
        ]));

        return [
            'id' => $item->article_id,
            'number' => $item->numero_article ?? '',
            'canonical_number' => $item->canonical_article_number ?? $item->numero_article ?? '',
            'has_duplicate_suffix' => (bool) ($item->has_duplicate_suffix ?? false),
            'order' => $item->ordre_affichage ?? 0,
            'content' => $item->contenu_texte,
            'document_id' => $item->document_id,
            'document_slug' => $item->document_slug ?? null,
            'document_title' => $item->document_title ?? '',
            'document_descriptive_label' => $item->document_descriptive_label ?? null,
            'document_type' => $item->document_type_code ?? '',
            'node_title' => $item->node_title ?? '',
            'breadcrumb' => $breadcrumb,
            'legal_scope' => $item->legal_scope ?? 'national',
            'institution_id' => $item->institution_id ?? null,
            'institution' => $item->institution_name ?? null,
            'date_publication' => $item->date_publication ?? null,
            // Journal Officiel d'origine : permet au client de proposer un
            // retour vers le JO ayant publié ce texte (null si non rattaché).
            'official_journal_id' => $item->official_journal_id ?? null,
            'official_journal' => isset($item->official_journal_id) ? [
                'id' => $item->official_journal_id,
                'title' => $item->official_journal_title ?? null,
                'publication_date' => $item->official_journal_date ?? null,
            ] : null,
            'validation_status' => $item->validation_status ?? 'validated',
            'score' => isset($item->total_score) ? round((float) $item->total_score, 4) : 0,
        ];
    }

    /**
     * Run a pure full-text library search and return the paginated, mapped rows.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function lexicalArticleSearch(
        string $search,
        array $filters = [],
        string $sort = 'relevance',
        int $perPage = 12,
        bool $withSemantic = false,
    ): LengthAwarePaginator {
        $query = $this->baseArticleQuery();
        $this->applyArticleFilters($query, $filters);
        $this->applyLexicalScoring($query, $search, $withSemantic);

        if ($sort === 'date_desc') {
            $query->orderByDesc('ld.date_publication');
        } elseif ($sort === 'date_asc') {
            $query->orderBy('ld.date_publication');
        } else {
            $query->orderByDesc('total_score');
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn ($item) => $this->mapArticleRow($item));

        return $paginator;
    }

    /**
     * Fetch the top-K lexical matches as plain arrays for the AI synthesis context.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function lexicalArticleContext(string $search, array $filters = [], int $limit = 5): array
    {
        $query = $this->baseArticleQuery();
        $this->applyArticleFilters($query, $filters);
        $this->applyLexicalScoring($query, $search, withSemantic: true);

        return $query->orderByDesc('total_score')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => $this->mapArticleRow($item))
            ->all();
    }

    /**
     * Fetch a single validated article with its full context, or null if absent.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchArticleContext(string $articleId): ?array
    {
        $item = $this->baseArticleQuery()->where('a.id', $articleId)->first();

        return $item ? $this->mapArticleRow($item) : null;
    }

    /**
     * Mots structurels écartés du full-text : présents dans presque tous les
     * textes juridiques, ils ne discriminent rien et noient la pertinence.
     */
    protected array $structuralStopWords = [
        'article', 'articles', 'alinea', 'alineas', 'paragraphe', 'paragraphes',
    ];

    /**
     * Seuil de `strict_word_similarity` (pg_trgm) pour le filet trigram.
     *
     * Calibré pour rattraper la même famille de mots (« dote » → 0.5 pour
     * « dot », 0.375 pour « dotal ») tout en écartant les faux amis
     * (« dotation » ≈ 0.27 sur « dote »).
     */
    protected float $fuzzyThreshold = 0.35;

    /**
     * Nombre maximal de candidats ajoutés par le seul filet sémantique
     * (voir {@see semanticCandidateIds}). Borne le bruit à la source : avec ce
     * modèle d'embedding, un seuil de distance ne discrimine presque rien sur
     * ce corpus — c'est le NOMBRE de candidats, pas leur distance, qui doit
     * être plafonné.
     */
    protected int $semanticTopK = 40;

    /**
     * Format query for Postgres to_tsquery
     */
    protected function formatTsQuery(string $query): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query);
        $parts = array_filter(explode(' ', trim($clean)), function ($w) {
            // ≥ 3 caractères : laisse passer les termes juridiques courts mais
            // décisifs (« dot », « dol », « TVA », « RCS ») que l'ancien seuil
            // (> 3) écartait, tout en filtrant le bruit de 1-2 lettres.
            return mb_strlen($w) >= 3
                && ! in_array(mb_strtolower($this->stripDiacritics($w)), $this->structuralStopWords, true);
        });

        if (empty($parts)) {
            return '';
        }

        return implode(' | ', $parts);
    }

    /**
     * Retire les accents pour comparer un mot à la liste des stop-words.
     */
    protected function stripDiacritics(string $value): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $normalized !== false ? $normalized : $value;
    }
}
