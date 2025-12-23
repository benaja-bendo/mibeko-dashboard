<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\Institution;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CurationController extends Controller
{
    public function index(Request $request)
    {
        $query = LegalDocument::query()
            ->with(['type', 'institution'])
            ->withCount(['articles', 'articles as validated_articles_count' => function ($query) {
                $query->where('validation_status', 'validated');
            }]);

        // Filtering
        if ($request->filled('search')) {
            $query->where('titre_officiel', 'ilike', '%' . $request->search . '%');
        }

        if ($request->filled('type')) {
            $query->where('type_code', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('curation_status', $request->status);
        }

        $documents = $query->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($doc) {
                $progression = $doc->articles_count > 0 
                    ? round(($doc->validated_articles_count / $doc->articles_count) * 100) 
                    : 0;

                // Mock quality score logic
                $qualityScore = 85; // Default mock
                if ($doc->articles_count === 0) $qualityScore = 0;
                elseif ($progression > 90) $qualityScore = 95;
                elseif ($progression > 50) $qualityScore = 80;

                return [
                    'id' => $doc->id,
                    'title' => $doc->titre_officiel,
                    'type' => $doc->type->nom ?? 'N/A',
                    'type_code' => $doc->type_code,
                    'institution' => $doc->institution->nom ?? 'N/A',
                    'date' => $doc->date_publication?->format('Y-m-d'),
                    'articles_count' => $doc->articles_count,
                    'status' => $doc->curation_status,
                    'progression' => $progression,
                    'quality_score' => $qualityScore,
                ];
            });

        return Inertia::render('Curation/index', [
            'documents' => $documents,
            'filters' => $request->only(['search', 'type', 'status']),
            'document_types' => DocumentType::all(['code', 'nom']),
            'institutions' => Institution::all(['id', 'nom']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type_code' => 'required|exists:document_types,code',
            'institution_id' => 'required|exists:institutions,id',
            'titre_officiel' => 'required|string|max:1000',
            'reference_nor' => 'nullable|string|max:100',
            'date_publication' => 'nullable|date',
            'source_url' => 'nullable|string|max:2048',
            'curation_status' => 'required|string|in:draft,review,validated,published',
        ]);

        $document = LegalDocument::create($validated);

        return redirect()->route('curation.show', $document)->with('success', 'Document créé avec succès.');
    }

    public function destroy(LegalDocument $document)
    {
        $document->delete();

        return back()->with('success', 'Document supprimé.');
    }

    public function show(LegalDocument $document)
    {
        $document->load(['type', 'structureNodes' => function ($query) {
            $query->orderBy('sort_order')->orderBy('tree_path');
        }, 'articles' => function ($query) {
            $query->orderBy('ordre_affichage'); // Use ordre_affichage for articles as per schema, maybe aliased to order in frontend
        }]);

        return Inertia::render('Curation/workstation', [
            'document' => [
                'id' => $document->id,
                'title' => $document->titre_officiel,
                'source_url' => $document->source_url,
                'status' => $document->curation_status,
                'date_signature' => $document->date_signature?->format('Y-m-d'),
                'date_publication' => $document->date_publication?->format('Y-m-d'),
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
            'title' => 'sometimes|string|max:1000',
            'status' => 'sometimes|string|in:draft,review,validated,published',
            'date_signature' => 'nullable|date',
            'date_publication' => 'nullable|date',
        ]);

        $updateData = [];
        if (array_key_exists('title', $validated)) {
            $updateData['titre_officiel'] = $validated['title'];
        }
        if (array_key_exists('status', $validated)) {
            $updateData['curation_status'] = $validated['status'];
        }
        if (array_key_exists('date_signature', $validated)) {
            $updateData['date_signature'] = $validated['date_signature'];
        }
        if (array_key_exists('date_publication', $validated)) {
            $updateData['date_publication'] = $validated['date_publication'];
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
                'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
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
            'update_in_place' => 'boolean',
            'create_new_version' => 'boolean',
            'valid_from' => 'date',
            'modification_reason' => 'nullable|string',
        ]);

        $article->update($request->only(['numero_article', 'validation_status', 'parent_node_id']));

        if ($request->has('content')) {
            $currentContent = $article->latestVersion?->contenu_texte;

            // Only process if content has changed
            if ($currentContent !== $request->content) {
                if ($request->boolean('update_in_place')) {
                    // Update the current version in-place (no history)
                    if ($article->latestVersion) {
                        $article->latestVersion->update([
                            'contenu_texte' => $request->content,
                        ]);
                    } else {
                        // No existing version, create one
                        $article->versions()->create([
                            'contenu_texte' => $request->content,
                            'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                        ]);
                    }
                } elseif ($request->boolean('create_new_version')) {
                    // Create a new version with full history tracking
                    $validFrom = $request->input('valid_from', now()->toDateString());

                    // Close the previous version's validity period
                    if ($article->latestVersion) {
                        DB::statement(
                            'UPDATE article_versions SET validity_period = daterange(lower(validity_period), ?) WHERE id = ?',
                            [$validFrom, $article->latestVersion->id]
                        );
                    }

                    // Create new version with open-ended validity period
                    $article->versions()->create([
                        'contenu_texte' => $request->content,
                        'validity_period' => ArticleVersion::makeValidityPeriod($validFrom),
                        // TODO: Could also store modification_reason in a separate column if needed
                    ]);
                } else {
                    // Default behavior: create new version (for backwards compatibility)
                    $today = now()->toDateString();

                    if ($article->latestVersion) {
                        DB::statement(
                            'UPDATE article_versions SET validity_period = daterange(lower(validity_period), ?) WHERE id = ?',
                            [$today, $article->latestVersion->id]
                        );
                    }

                    $article->versions()->create([
                        'contenu_texte' => $request->content,
                        'validity_period' => ArticleVersion::makeValidityPeriod($today),
                    ]);
                }
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
