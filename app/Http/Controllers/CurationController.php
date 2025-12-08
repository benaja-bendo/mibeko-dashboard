<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CurationController extends Controller
{
    public function index()
    {
        return Inertia::render('Curation/Index', [
            'documents' => LegalDocument::query()
                ->with(['type', 'institution'])
                ->withCount('articles')
                ->latest('updated_at')
                ->paginate(20)
                ->through(fn ($doc) => [
                    'id' => $doc->id,
                    'title' => $doc->titre_officiel,
                    'type' => $doc->type->nom ?? 'N/A',
                    'date' => $doc->date_publication?->format('Y-m-d'),
                    'articles_count' => $doc->articles_count,
                    'status' => $doc->curation_status,
                ]),
        ]);
    }

    public function show(LegalDocument $document)
    {
        $document->load(['type', 'structureNodes' => function ($query) {
            $query->orderBy('tree_path');
        }, 'articles' => function ($query) {
            $query->orderBy('ordre_affichage');
        }]);

        // Transform structure for frontend tree
        // This is a simplified version, a real implementation might need a recursive transformer
        // depending on how the frontend expects the data.
        // For now, sending flat lists and letting frontend handle hierarchy or using a transformer here.
        // Let's send flat lists for flexibility.

        return Inertia::render('Curation/Workstation', [
            'document' => [
                'id' => $document->id,
                'title' => $document->titre_officiel,
                'source_url' => $document->source_url,
                'status' => $document->curation_status,
            ],
            'structure' => $document->structureNodes,
            'articles' => $document->articles->map(function ($article) {
                return [
                    'id' => $article->id,
                    'numero' => $article->numero_article,
                    'content' => $article->latestVersion?->contenu_texte ?? '', // Assuming latestVersion relation exists or we need to fetch it
                    'parent_id' => $article->parent_node_id,
                    'order' => $article->ordre_affichage,
                ];
            }),
        ]);
    }

    public function updateStructure(Request $request, LegalDocument $document)
    {
        // Logic to move nodes/articles
        // This will require ltree manipulation
        return back()->with('success', 'Structure updated');
    }

    public function updateContent(Request $request, LegalDocument $document)
    {
        // Logic to update article content
        return back()->with('success', 'Content updated');
    }
}
