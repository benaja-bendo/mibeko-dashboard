<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Home
 */
class HomeController extends Controller
{
    /**
     * Get home page data.
     *
     * Returns popular codes, recently added documents, and AI search suggestions.
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Popular Codes (Priority documents)
        $popularCodes = LegalDocument::query()
            ->with(['type'])
            ->whereIn('titre_officiel', [
                'Constitution de la République du Congo',
                'Code Civil',
                'Code Pénal',
                'Code du Travail',
                'Code de la Famille'
            ])
            ->orWhere('type_code', 'CONST')
            ->limit(6)
            ->get();

        // 2. Recently Added
        $recentlyAdded = LegalDocument::query()
            ->with(['type'])
            ->latest()
            ->limit(5)
            ->get();

        // 3. AI Search Suggestions (Hardcoded for now, could be dynamic)
        $suggestions = [
            'Quels sont mes droits en cas de licenciement abusif ?',
            'Comment contester un bail commercial ?',
            'Quelle est la procédure pour une faute grave ?',
            'Quelles sont les indemnités de licenciement ?',
            'Comment créer une entreprise en République du Congo ?'
        ];

        return $this->success([
            'popular_codes' => LegalDocumentResource::collection($popularCodes),
            'recently_added' => LegalDocumentResource::collection($recentlyAdded),
            'ai_suggestions' => $suggestions,
        ], 'Données de la page d\'accueil récupérées avec succès');
    }
}
