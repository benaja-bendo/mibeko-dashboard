<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * @group Library Home
 *
 * Accueil de la Bibliothèque (poste de travail web).
 *
 * Alimente l'état « avant recherche » de la page /app/library : textes
 * fondamentaux, derniers textes publiés, statistiques du fonds documentaire
 * et suggestions de recherche. Aucune IA — contenu identique pour tous les
 * utilisateurs, mis en cache côté serveur.
 */
class LibraryHomeController extends Controller
{
    /**
     * Nombre de textes fondamentaux servis à l'accueil.
     */
    private const ESSENTIAL_LIMIT = 6;

    /**
     * Natures de texte éligibles au rang de « texte fondamental ».
     *
     * `AU` est bien le code des actes uniformes OHADA — vérifié dans le
     * référentiel, pas deviné : `ACTE_UNIFORME` n'existe pas.
     */
    private const ESSENTIAL_TYPES = ['CONST', 'CODE', 'AU'];

    /**
     * Périmètres annoncés au public (« République du Congo · espace OHADA »).
     * `communautaire` en est exclu : c'est ce qui faisait remonter un code
     * CEMAC parmi les textes essentiels du Congo.
     */
    private const ESSENTIAL_SCOPES = ['national', 'ohada'];

    /**
     * Répartition des places, appliquée dans l'ordre.
     *
     * Un tri unique — quel qu'il soit — ne peut pas tenir les deux moitiés de
     * la promesse : classés au volume, les codes nationaux (2 826 articles pour
     * le seul Code civil) occupent toutes les places et l'OHADA disparaît ;
     * classés par nature, la Constitution passe derrière n'importe quel code.
     * D'où des quotas explicites. Les places qu'un groupe ne remplit pas sont
     * rendues aux suivants, puis au repli — une base pauvre (développement,
     * corpus en cours de constitution) reste servie.
     *
     * Le périmètre prime sur la nature dans les deux derniers groupes : le
     * typage est faillible — l'Acte uniforme portant droit commercial général
     * est enregistré `CODE` en production — alors que `legal_scope` est fiable.
     */
    private const ESSENTIAL_QUOTAS = [
        ['column' => 'type_code', 'value' => 'CONST', 'take' => 1],
        ['column' => 'legal_scope', 'value' => 'national', 'take' => 3],
        ['column' => 'legal_scope', 'value' => 'ohada', 'take' => 2],
    ];

    /**
     * Suggestions de recherche affichées sur l'accueil de la Bibliothèque.
     */
    private const SEARCH_SUGGESTIONS = [
        'rupture du contrat de travail',
        'article 45 code du travail',
        'constitution d\'une société (OHADA)',
        'droit de rétractation du consommateur',
        'procédure de licenciement pour faute grave',
        'bail à usage professionnel',
    ];

    /**
     * Get library home data.
     *
     * Returns the curated content of the library landing state:
     * - **stats**: published documents, validated articles and institutions counts.
     * - **essential_documents**: constitution and major codes.
     * - **recent_documents**: latest published documents.
     * - **suggestions**: example search queries.
     *
     * @response 200 {
     *  "success": true,
     *  "message": "Accueil de la bibliothèque récupéré avec succès",
     *  "data": {
     *    "stats": { "documents": 120, "articles": 14500, "institutions": 12 },
     *    "essential_documents": [ { "id": "uuid", "title": "Code du Travail" } ],
     *    "recent_documents": [ { "id": "uuid", "title": "Loi n°..." } ],
     *    "suggestions": [ "rupture du contrat de travail" ]
     *  }
     * }
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember('library:home', now()->addMinutes(10), function (): array {
            $essentials = $this->essentialDocuments();

            $recents = LegalDocument::query()
                ->published()
                ->with('type')
                ->withCount('articles')
                ->orderByRaw('date_publication DESC NULLS LAST')
                ->orderByDesc('created_at')
                ->limit(6)
                ->get();

            return [
                'stats' => [
                    'documents' => LegalDocument::query()->published()->count(),
                    'articles' => Article::query()
                        ->whereHas('document', fn ($q) => $q->published())
                        ->count(),
                    'institutions' => Institution::query()->count(),
                ],
                'essential_documents' => $this->mapDocuments($essentials),
                'recent_documents' => $this->mapDocuments($recents),
                'suggestions' => self::SEARCH_SUGGESTIONS,
            ];
        });

        return $this->success($data, 'Accueil de la bibliothèque récupéré avec succès');
    }

    /**
     * Sélectionne les textes fondamentaux affichés à l'accueil.
     *
     * Cette sélection était auparavant faite sur l'intitulé — `type_code =
     * 'CONST'` ou un titre commençant par « code » ou « constitution » —, triée
     * par ordre alphabétique et coupée à six. Trois conséquences se voyaient en
     * production le 07/09/2026 (mibeko-dashboard#114) :
     *
     *  1. **un texte abrogé en tête de la page d'accueil.** Aucun filtre ne
     *     portait sur `statut`, si bien que l'Acte fondamental du 24 octobre
     *     1997 — `statut = 'abroge'`, et vérifié comme tel dans notre propre
     *     base — était servi badgé « Constitution », tandis que la Constitution
     *     en vigueur n'était pas retenue : son intitulé commence par
     *     « Republique », sans accent ;
     *  2. **du droit hors périmètre** : un code CEMAC entrait parce que son
     *     titre commence par « Code », sur une page qui annonce « République du
     *     Congo · espace OHADA » ;
     *  3. **« essentiel » ne voulait rien dire** : l'ordre alphabétique plus
     *     `limit(6)` interdisait structurellement au Code du travail, au Code
     *     pénal ou aux actes uniformes d'y figurer un jour.
     *
     * La sélection repose désormais sur des propriétés du document, jamais sur
     * son intitulé : il est en vigueur, il est du droit qui nous concerne, et
     * c'est un texte consolidé (`STOCK`) et non un acte unitaire issu d'un
     * Journal officiel (`FLUX`). Ce dernier critère n'est pas décoratif : en
     * production, un « Journal officiel n° 1-2011 » est enregistré avec le type
     * `AU` et serait remonté au rang de texte fondamental sans lui.
     *
     * À volume égal le plus fourni passe devant, puis l'intitulé départage :
     * deux appels successifs renvoient le même ordre.
     */
    private function essentialDocuments(): Collection
    {
        $eligible = fn () => LegalDocument::query()
            ->published()
            ->where('statut', 'vigueur')
            ->where('document_role', 'STOCK')
            ->whereIn('legal_scope', self::ESSENTIAL_SCOPES)
            ->whereIn('type_code', self::ESSENTIAL_TYPES)
            ->with('type')
            ->withCount('articles')
            ->orderByDesc('articles_count')
            ->orderBy('titre_officiel');

        $picked = new Collection;

        $fill = function (?array $quota) use ($eligible, &$picked): void {
            $take = $quota === null
                ? self::ESSENTIAL_LIMIT - $picked->count()
                : min($quota['take'], self::ESSENTIAL_LIMIT - $picked->count());

            if ($take <= 0) {
                return;
            }

            $query = $eligible();

            if ($quota !== null) {
                $query->where($quota['column'], $quota['value']);
            }

            if ($picked->isNotEmpty()) {
                $query->whereNotIn('id', $picked->pluck('id')->all());
            }

            $picked = $picked->merge($query->limit($take)->get());
        };

        foreach (self::ESSENTIAL_QUOTAS as $quota) {
            $fill($quota);
        }

        // Repli : ce que les quotas n'ont pas rempli (corpus incomplet, ou un
        // périmètre encore vide) est complété sans distinction de groupe.
        $fill(null);

        return $picked;
    }

    /**
     * Liste des thèmes de vie avec le nombre de textes publiés rattachés.
     *
     * Alimente la bande « Parcourir par thème » de la Bibliothèque. Mis en cache
     * côté serveur (contenu identique pour tous).
     */
    public function themes(): JsonResponse
    {
        $themes = Cache::remember('library:themes', now()->addMinutes(10), function (): array {
            return Tag::query()
                ->withCount(['legalDocuments as documents_count' => fn ($q) => $q->published()])
                ->whereHas('legalDocuments', fn ($q) => $q->published())
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'icon' => $tag->icon,
                    'description' => $tag->description,
                    'documents_count' => (int) $tag->documents_count,
                ])->all();
        });

        return $this->success($themes, 'Thèmes récupérés avec succès');
    }

    /**
     * Textes publiés rattachés à un thème — alimente la vue « Parcourir par
     * thème » (liste de documents, pas de recherche full-text).
     */
    public function themeDocuments(string $slug): JsonResponse
    {
        $theme = Tag::where('slug', $slug)->firstOrFail();

        $documents = $theme->legalDocuments()
            ->published()
            ->with('type')
            ->withCount('articles')
            ->orderByRaw('legal_documents.date_publication DESC NULLS LAST')
            ->orderByDesc('legal_documents.created_at')
            ->limit(60)
            ->get();

        return $this->success([
            'theme' => [
                'id' => $theme->id,
                'name' => $theme->name,
                'slug' => $theme->slug,
                'icon' => $theme->icon,
                'description' => $theme->description,
            ],
            'documents' => $this->mapDocuments($documents),
        ], 'Textes du thème récupérés avec succès');
    }

    /**
     * Map documents to the slim shape consumed by the library landing state.
     *
     * @param  Collection<int, LegalDocument>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function mapDocuments(Collection $documents): array
    {
        return $documents->map(fn (LegalDocument $doc): array => [
            'id' => $doc->id,
            'slug' => $doc->slug,
            'title' => $doc->titre_officiel,
            'type_code' => $doc->type_code,
            'type_name' => $doc->type->nom ?? null,
            'legal_scope' => $doc->legal_scope ?? 'national',
            'date_publication' => $doc->date_publication?->toDateString(),
            'articles_count' => $doc->articles_count ?? 0,
        ])->all();
    }
}
