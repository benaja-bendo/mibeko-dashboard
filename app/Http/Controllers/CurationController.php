<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $query->orderBy('sort_order')->orderBy('tree_path');
        }, 'articles' => function ($query) {
            $query->orderBy('ordre_affichage'); // Use ordre_affichage for articles as per schema, maybe aliased to order in frontend
        }]);

        return Inertia::render('Curation/Workstation', [
            'document' => [
                'id' => $document->id,
                'title' => $document->titre_officiel,
                'source_url' => $document->source_url,
                'status' => $document->curation_status,
            ],
            'structure' => $document->structureNodes->map(function ($node) {
                return [
                    'id' => $node->id,
                    'type_unite' => $node->type_unite,
                    'numero' => $node->numero,
                    'titre' => $node->titre,
                    'tree_path' => $node->tree_path,
                    'status' => $node->validation_status,
                    'order' => $node->sort_order,
                ];
            }),
            'articles' => $document->articles->map(function ($article) {
                return [
                    'id' => $article->id,
                    'numero' => $article->numero_article,
                    'content' => $article->latestVersion?->contenu_texte ?? '',
                    'parent_id' => $article->parent_node_id,
                    'order' => $article->ordre_affichage,
                    'status' => $article->validation_status,
                ];
            }),
        ]);
    }

    public function update(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:1000', // titre_officiel
            'status' => 'sometimes|string|in:draft,published,archived', // curation_status
        ]);

        $updateData = [];
        if (array_key_exists('title', $validated)) {
            $updateData['titre_officiel'] = $validated['title'];
        }
        if (array_key_exists('status', $validated)) {
            $updateData['curation_status'] = $validated['status'];
        }

        if (! empty($updateData)) {
            $document->update($updateData);
        }

        return back()->with('success', 'Document mis à jour');
    }

    // --- Nodes ---

    public function storeNode(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'type_unite' => 'required|string',
            'numero' => 'nullable|string',
            'titre' => 'nullable|string',
            'tree_path' => 'required|string', // Should probably be calculated but allowing manual for now
            'order' => 'integer',
        ]);

        $payload = [
            'type_unite' => $validated['type_unite'],
            'numero' => $validated['numero'] ?? null,
            'titre' => $validated['titre'] ?? null,
            'tree_path' => $validated['tree_path'],
            'sort_order' => $validated['order'] ?? 0,
        ];

        $document->structureNodes()->create($payload);

        return back()->with('success', 'Élément de structure ajouté');
    }

    public function updateNode(Request $request, LegalDocument $document, StructureNode $node)
    {
        \Illuminate\Support\Facades\Log::info('updateNode payload', $request->all());

        $validated = $request->validate([
            'type_unite' => 'string',
            'numero' => 'nullable|string',
            'titre' => 'nullable|string',
            'tree_path' => 'string',
            'validation_status' => 'string|in:pending,in_progress,validated',
            'sort_order' => 'integer',
            'order' => 'integer',
        ]);

        $data = $validated;
        if (array_key_exists('order', $data) && ! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = $data['order'];
            unset($data['order']);
        }

        $node->update($data);

        return back()->with('success', 'Structure mise à jour');
    }

    public function destroyNode(LegalDocument $document, StructureNode $node)
    {
        $node->delete();

        return back()->with('success', 'Élément supprimé');
    }

    // --- Articles ---

    public function storeArticle(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'parent_node_id' => 'nullable|exists:structure_nodes,id',
            'numero_article' => 'required|string',
            'content' => 'nullable|string',
            'ordre_affichage' => 'integer',
        ]);

        $article = $document->articles()->create([
            'parent_node_id' => $validated['parent_node_id'],
            'numero_article' => $validated['numero_article'],
            'ordre_affichage' => $validated['ordre_affichage'] ?? 0,
        ]);

        if (! empty($validated['content'])) {
            $article->versions()->create([
                'contenu_texte' => $validated['content'],
                'valid_from' => now(),
                // 'modifie_par_document_id' => $document->id // optional
            ]);
        }

        return back()->with('success', 'Article ajouté');
    }

    public function updateArticle(Request $request, LegalDocument $document, Article $article)
    {
        \Illuminate\Support\Facades\Log::info('updateArticle payload', $request->all());

        $validated = $request->validate([
            'numero_article' => 'string',
            'content' => 'nullable|string',
            'validation_status' => 'string|in:pending,in_progress,validated',
            'parent_node_id' => 'nullable|exists:structure_nodes,id',
        ]);

        $article->update($request->only(['numero_article', 'validation_status', 'parent_node_id']));

        if ($request->has('content')) {
            // Create new version if content changed
            $currentContent = $article->latestVersion?->contenu_texte;
            if ($currentContent !== $request->content) {
                $article->versions()->create([
                    'contenu_texte' => $request->content,
                    'valid_from' => now(),
                ]);
            }
        }

        return back()->with('success', 'Article mis à jour');
    }

    public function destroyArticle(LegalDocument $document, Article $article)
    {
        $article->delete();

        return back()->with('success', 'Article supprimé');
    }

    // --- Reordering ---

    public function reorder(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|uuid',
            'items.*.type' => 'required|in:node,article',
            'items.*.order' => 'required|integer',
            'items.*.parent_id' => 'nullable|uuid', // For hierarchy changes
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['items'] as $item) {
                if ($item['type'] === 'node') {
                    StructureNode::where('id', $item['id'])->update([
                        'sort_order' => $item['order'],
                        // 'tree_path' => ... // managing tree_path updates is complex, skipping for MVP unless requested
                    ]);
                } else {
                    Article::where('id', $item['id'])->update([
                        'ordre_affichage' => $item['order'],
                        'parent_node_id' => $item['parent_id'] ?? null,
                    ]);
                }
            }
        });

        return back()->with('success', 'Ordre mis à jour');
    }

    // --- Legacy / Specific Updates ---

    public function updateSourceUrl(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'source_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $document->update([
            'source_url' => $validated['source_url'],
        ]);

        return back()->with('success', 'URL source mise à jour');
    }

    // Kept for backward compatibility if needed, but updateArticle covers it
    public function updateContent(Request $request, LegalDocument $document)
    {
        // Redirect to generic updateArticle or implement specific logic here
        // This was likely used by the simpler editor
        // Assuming 'article_id' is passed
        $articleId = $request->input('article_id');
        $article = $document->articles()->where('id', $articleId)->firstOrFail();

        return $this->updateArticle($request, $document, $article);
    }
}
