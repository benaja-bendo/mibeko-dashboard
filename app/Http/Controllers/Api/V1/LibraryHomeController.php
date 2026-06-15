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
            $essentials = LegalDocument::query()
                ->published()
                ->with('type')
                ->withCount('articles')
                ->where(function ($query) {
                    $query->where('type_code', 'CONST')
                        ->orWhere('titre_officiel', 'ILIKE', 'code %')
                        ->orWhere('titre_officiel', 'ILIKE', 'constitution%');
                })
                ->orderBy('titre_officiel')
                ->limit(6)
                ->get();

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
            'title' => $doc->titre_officiel,
            'type_code' => $doc->type_code,
            'type_name' => $doc->type->nom ?? null,
            'legal_scope' => $doc->legal_scope ?? 'national',
            'date_publication' => $doc->date_publication?->toDateString(),
            'articles_count' => $doc->articles_count ?? 0,
        ])->all();
    }
}
