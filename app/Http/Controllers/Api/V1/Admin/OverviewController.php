<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\DocumentType;
use App\Models\ExtractionRun;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * KPIs de l'accueil de l'espace administration.
 *
 * Volontairement en lecture seule et agrégé : sert de tableau de bord de
 * pilotage et de points d'entrée vers les rubriques détaillées.
 *
 * @group Admin / Vue d'ensemble
 */
class OverviewController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success([
            'content' => [
                'documents' => LegalDocument::count(),
                'articles' => Article::count(),
                'official_journals' => OfficialJournal::count(),
            ],
            'referentiels' => [
                'document_types' => DocumentType::count(),
                'institutions' => Institution::count(),
                'tags' => Tag::count(),
            ],
            'people' => [
                'users' => User::count(),
            ],
            'attention' => [
                'open_flags' => CurationFlag::where('resolved', false)->count(),
                'failed_extractions' => ExtractionRun::where('status', 'failed')->count(),
            ],
        ], 'Vue d\'ensemble admin récupérée avec succès');
    }
}
