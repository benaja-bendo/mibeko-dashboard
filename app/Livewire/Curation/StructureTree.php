<?php

namespace App\Livewire\Curation;

use Livewire\Component;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class StructureTree extends Component
{
    public LegalDocument $document;
    
    public Collection $rootNodes;
    public ?string $selectedRootPath = null;

    public Collection $nodes;
    public Collection $articles;

    public array $tree = [];

    public ?string $selectedArticleId = null;

    public ?string $editingNodeId = null;
    public string $editingNodeType = '';
    public ?string $editingNodeNumero = null;
    public ?string $editingNodeTitle = null;

    public ?string $editingArticleId = null;
    public ?string $editingArticleNumero = null;

    public function mount(LegalDocument $document)
    {
        $this->document = $document;

        $this->rootNodes = StructureNode::query()
            ->where('document_id', $document->id)
            ->where('tree_path', 'not like', '%.%')
            ->orderBy('sort_order')
            ->orderBy('tree_path')
            ->get();

        $title = (string) $document->titre_officiel;
        $matchedRoot = $this->rootNodes->first(function (StructureNode $node) use ($title): bool {
            $needle = trim((string) ($node->numero ?? $node->titre ?? ''));
            if ($needle === '') {
                return false;
            }

            return str_contains($title, $needle);
        });

        $this->selectedRootPath = $matchedRoot?->tree_path ?? $this->rootNodes->first()?->tree_path;

        $this->loadData();
    }

    public function updatedSelectedRootPath(): void
    {
        $this->loadData();
    }

    #[On('articleSelected')]
    public function setSelectedArticleId(string $articleId): void
    {
        $this->selectedArticleId = $articleId;
    }

    public function selectArticle(string $articleId): void
    {
        $this->dispatch('articleSelected', $articleId);
    }

    public function startEditNode(string $nodeId): void
    {
        $node = $this->nodes->firstWhere('id', $nodeId)
            ?? StructureNode::query()->whereKey($nodeId)->first();

        if (! $node) {
            return;
        }

        $this->editingNodeId = (string) $node->id;
        $this->editingNodeType = (string) $node->type_unite;
        $this->editingNodeNumero = $node->numero;
        $this->editingNodeTitle = $node->titre;
        $this->editingArticleId = null;
    }

    public function cancelEditNode(): void
    {
        $this->editingNodeId = null;
    }

    public function saveNode(): void
    {
        if (blank($this->editingNodeId)) {
            return;
        }

        $node = StructureNode::query()->whereKey($this->editingNodeId)->first();
        if (! $node) {
            $this->editingNodeId = null;
            return;
        }

        $node->update([
            'type_unite' => trim($this->editingNodeType) !== '' ? trim($this->editingNodeType) : $node->type_unite,
            'numero' => filled($this->editingNodeNumero) ? trim((string) $this->editingNodeNumero) : null,
            'titre' => filled($this->editingNodeTitle) ? trim((string) $this->editingNodeTitle) : null,
        ]);

        $this->editingNodeId = null;
        $this->loadData();
    }

    public function startEditArticle(string $articleId): void
    {
        $article = $this->articles->firstWhere('id', $articleId)
            ?? Article::query()->whereKey($articleId)->first();

        if (! $article) {
            return;
        }

        $this->editingArticleId = (string) $article->id;
        $this->editingArticleNumero = $article->numero_article;
        $this->editingNodeId = null;
    }

    public function cancelEditArticle(): void
    {
        $this->editingArticleId = null;
    }

    public function saveArticle(): void
    {
        if (blank($this->editingArticleId)) {
            return;
        }

        $article = Article::query()->whereKey($this->editingArticleId)->first();
        if (! $article) {
            $this->editingArticleId = null;
            return;
        }

        $article->update([
            'numero_article' => filled($this->editingArticleNumero) ? trim((string) $this->editingArticleNumero) : $article->numero_article,
        ]);

        $this->editingArticleId = null;
        $this->loadData();
    }

    public function addChildNode(string $parentNodeId): void
    {
        $parent = $this->nodes->firstWhere('id', $parentNodeId)
            ?? StructureNode::query()->whereKey($parentNodeId)->first();

        if (! $parent) {
            return;
        }

        $nodeId = (string) Str::uuid();
        $safeId = str_replace('-', '_', $nodeId);
        $treePath = (string) $parent->tree_path . '.' . $safeId;

        $siblings = $this->nodes->filter(function (StructureNode $node) use ($parent): bool {
            return $this->getParentPath((string) $node->tree_path) === (string) $parent->tree_path;
        });

        $nextOrder = ((int) ($siblings->max('sort_order') ?? 0)) + 10;

        StructureNode::create([
            'id' => $nodeId,
            'document_id' => $this->document->id,
            'type_unite' => 'Section',
            'numero' => null,
            'titre' => 'Nouvelle section',
            'tree_path' => $treePath,
            'sort_order' => $nextOrder,
            'validation_status' => 'pending',
        ]);

        $this->loadData();
    }

    public function addArticle(string $parentNodeId): void
    {
        $node = $this->nodes->firstWhere('id', $parentNodeId)
            ?? StructureNode::query()->whereKey($parentNodeId)->first();

        if (! $node) {
            return;
        }

        $siblings = $this->articles->filter(fn (Article $a): bool => (string) $a->parent_node_id === (string) $node->id);
        $nextOrder = ((int) ($siblings->max('ordre_affichage') ?? 0)) + 10;

        $article = Article::create([
            'document_id' => $this->document->id,
            'parent_node_id' => $node->id,
            'numero_article' => '?',
            'ordre_affichage' => $nextOrder,
            'validation_status' => 'draft',
        ]);

        $startDate = $this->document->date_publication?->format('Y-m-d') ?? now()->toDateString();

        ArticleVersion::create([
            'article_id' => $article->id,
            'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
            'contenu_texte' => '',
            'modifie_par_document_id' => $this->document->id,
            'validation_status' => 'draft',
        ]);

        $this->selectArticle((string) $article->id);
        $this->loadData();
    }

    public function moveNodeUp(string $nodeId): void
    {
        $this->moveNode($nodeId, -1);
    }

    public function moveNodeDown(string $nodeId): void
    {
        $this->moveNode($nodeId, 1);
    }

    protected function moveNode(string $nodeId, int $direction): void
    {
        $node = $this->nodes->firstWhere('id', $nodeId);
        if (! $node) {
            return;
        }

        $parentPath = $this->getParentPath((string) $node->tree_path);

        $siblings = $this->nodes
            ->filter(fn (StructureNode $n): bool => $this->getParentPath((string) $n->tree_path) === $parentPath)
            ->sortBy([['sort_order', 'asc'], ['tree_path', 'asc']])
            ->values();

        $index = $siblings->search(fn (StructureNode $n): bool => (string) $n->id === (string) $nodeId);
        if ($index === false) {
            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= $siblings->count()) {
            return;
        }

        $ordered = $siblings->all();
        [$moved] = array_splice($ordered, $index, 1);
        array_splice($ordered, $target, 0, [$moved]);

        foreach (array_values($ordered) as $i => $sibling) {
            $newOrder = $i * 10;
            if ((int) $sibling->sort_order !== $newOrder) {
                StructureNode::query()
                    ->whereKey($sibling->id)
                    ->update(['sort_order' => $newOrder]);
            }
        }

        $this->loadData();
    }

    public function moveArticleUp(string $articleId): void
    {
        $this->moveArticle($articleId, -1);
    }

    public function moveArticleDown(string $articleId): void
    {
        $this->moveArticle($articleId, 1);
    }

    protected function moveArticle(string $articleId, int $direction): void
    {
        $article = $this->articles->firstWhere('id', $articleId);
        if (! $article) {
            return;
        }

        $parentId = (string) $article->parent_node_id;

        $siblings = $this->articles
            ->filter(fn (Article $a): bool => (string) $a->parent_node_id === $parentId)
            ->sortBy('ordre_affichage')
            ->values();

        $index = $siblings->search(fn (Article $a): bool => (string) $a->id === (string) $articleId);
        if ($index === false) {
            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= $siblings->count()) {
            return;
        }

        $ordered = $siblings->all();
        [$moved] = array_splice($ordered, $index, 1);
        array_splice($ordered, $target, 0, [$moved]);

        foreach (array_values($ordered) as $i => $sibling) {
            $newOrder = $i * 10;
            if ((int) $sibling->ordre_affichage !== $newOrder) {
                Article::query()
                    ->whereKey($sibling->id)
                    ->update(['ordre_affichage' => $newOrder]);
            }
        }

        $this->loadData();
    }

    protected function loadData(): void
    {
        if (blank($this->selectedRootPath)) {
            $this->nodes = new Collection();
            $this->articles = new Collection();
            $this->tree = [];
            return;
        }

        $rootPath = $this->selectedRootPath;

        $this->nodes = StructureNode::query()
            ->where('document_id', $this->document->id)
            ->where(function ($query) use ($rootPath) {
                $query
                    ->where('tree_path', $rootPath)
                    ->orWhere('tree_path', 'like', $rootPath . '.%');
            })
            ->orderBy('sort_order')
            ->orderBy('tree_path')
            ->get();

        $this->articles = Article::query()
            ->select('articles.*')
            ->where('articles.document_id', $this->document->id)
            ->join('structure_nodes', 'structure_nodes.id', '=', 'articles.parent_node_id')
            ->where('structure_nodes.document_id', $this->document->id)
            ->where(function ($query) use ($rootPath) {
                $query
                    ->where('structure_nodes.tree_path', $rootPath)
                    ->orWhere('structure_nodes.tree_path', 'like', $rootPath . '.%');
            })
            ->orderBy('articles.ordre_affichage')
            ->get();

        $this->tree = $this->buildTree();
    }

    protected function buildTree(): array
    {
        $nodesByPath = [];

        foreach ($this->nodes as $node) {
            $nodesByPath[(string) $node->tree_path] = $node;
        }

        $childrenByParentPath = [];
        foreach ($this->nodes as $node) {
            $parentPath = $this->getParentPath((string) $node->tree_path) ?? '';
            $childrenByParentPath[$parentPath] ??= [];
            $childrenByParentPath[$parentPath][] = $node;
        }

        foreach ($childrenByParentPath as $parentPath => $children) {
            usort($children, function (StructureNode $a, StructureNode $b): int {
                $byOrder = ((int) $a->sort_order) <=> ((int) $b->sort_order);
                if ($byOrder !== 0) {
                    return $byOrder;
                }

                return strcmp((string) $a->tree_path, (string) $b->tree_path);
            });

            $childrenByParentPath[$parentPath] = $children;
        }

        $articlesByNodeId = [];
        foreach ($this->articles as $article) {
            $key = (string) $article->parent_node_id;
            $articlesByNodeId[$key] ??= [];
            $articlesByNodeId[$key][] = $article;
        }

        foreach ($articlesByNodeId as $nodeId => $list) {
            usort($list, fn (Article $a, Article $b): int => ((int) $a->ordre_affichage) <=> ((int) $b->ordre_affichage));
            $articlesByNodeId[$nodeId] = $list;
        }

        $makeNode = function (StructureNode $node) use (&$makeNode, $childrenByParentPath, $articlesByNodeId): array {
            $path = (string) $node->tree_path;

            $children = array_map(
                fn (StructureNode $child): array => $makeNode($child),
                $childrenByParentPath[$path] ?? []
            );

            $articles = array_map(function (Article $article): array {
                return [
                    'id' => (string) $article->id,
                    'numero_article' => (string) $article->numero_article,
                    'ordre_affichage' => (int) $article->ordre_affichage,
                    'validation_status' => (string) $article->validation_status,
                ];
            }, $articlesByNodeId[(string) $node->id] ?? []);

            return [
                'id' => (string) $node->id,
                'type_unite' => (string) $node->type_unite,
                'numero' => $node->numero,
                'titre' => $node->titre,
                'tree_path' => $path,
                'sort_order' => (int) $node->sort_order,
                'validation_status' => (string) $node->validation_status,
                'children' => $children,
                'articles' => $articles,
            ];
        };

        $rootNode = $nodesByPath[(string) $this->selectedRootPath] ?? null;
        if (! $rootNode) {
            return [];
        }

        return [$makeNode($rootNode)];
    }

    protected function getParentPath(string $treePath): ?string
    {
        $pos = strrpos($treePath, '.');
        if ($pos === false) {
            return null;
        }

        return substr($treePath, 0, $pos);
    }

    public function render()
    {
        return view('livewire.curation.structure-tree');
    }
}
