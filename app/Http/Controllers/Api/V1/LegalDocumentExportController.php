<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LegalDocumentExportController extends Controller
{
    /**
     * Export a full legal document to PDF.
     *
     * Generates a high-quality PDF version of the complete document including all its articles and structure.
     *
     * @param  string  $id  The UUID of the legal document.
     *
     * @response 200 binary The generated PDF file.
     */
    public function export(string $id): Response
    {
        // Increase timeout and memory for large legal documents
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $document = LegalDocument::query()
            ->with([
                'institution',
                'type',
                'structureNodes' => function ($q) {
                    $q->orderBy('sort_order');
                },
                'articles' => function ($q) {
                    $q->orderBy('ordre_affichage');
                },
                'articles.activeVersion',
                'articles.latestVersion',
            ])
            ->findOrFail($id);

        [$sections, $orphans] = $this->buildSections($document);

        if (ob_get_length()) {
            ob_end_clean();
        }
        $pdf = Pdf::loadView('documents.pro_document_pdf', compact('document', 'sections', 'orphans'));
        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);
        $pdf->setOption('isRemoteEnabled', true);

        $filename = Str::slug($document->titre_officiel ?? 'document').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Export a single article to PDF.
     *
     * Generates a PDF version of a specific article with its metadata and parent document info.
     *
     * @param  string  $id  The UUID of the article.
     *
     * @response 200 binary The generated PDF file.
     */
    public function exportArticle(string $id): Response
    {
        ini_set('memory_limit', '256M');
        $article = Article::query()
            ->with(['document', 'document.institution', 'document.type', 'parentNode', 'activeVersion'])
            ->findOrFail($id);

        $document = $article->document;

        $pdf = Pdf::loadView('documents.pro_article_pdf', compact('article', 'document'));
        $pdf->setPaper('a4');

        $filename = 'Article-'.$article->numero_article.'-'.Str::slug($document->titre_officiel ?? 'document').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Build the ordered, de-duplicated sections rendered in the document PDF.
     *
     * Ingested documents may carry duplicated structure nodes (same unit,
     * number and title) with the articles split between the copies; the
     * `tree_path` column holds opaque ids and is not sortable. Sections are
     * therefore ordered by `sort_order` and merged by their textual identity,
     * each one keeping its articles sorted by `ordre_affichage`.
     *
     * @return array{0: Collection<int, array{node: StructureNode, articles: Collection<int, Article>}>, 1: Collection<int, Article>}
     */
    private function buildSections(LegalDocument $document): array
    {
        $articlesByNode = $document->articles->groupBy('parent_node_id');

        /** @var Collection<int, array{node: StructureNode, articles: Collection<int, Article>}> $sections */
        $sections = collect();
        $indexByKey = [];

        foreach ($document->structureNodes as $node) {
            $key = mb_strtolower(trim(implode('|', [$node->type_unite, $node->numero, $node->titre])));
            $nodeArticles = $articlesByNode->get($node->id, collect());

            if (array_key_exists($key, $indexByKey)) {
                $existing = $sections[$indexByKey[$key]];
                $existing['articles'] = $existing['articles']->concat($nodeArticles);
                $sections[$indexByKey[$key]] = $existing;

                continue;
            }

            $indexByKey[$key] = $sections->count();
            $sections->push(['node' => $node, 'articles' => $nodeArticles]);
        }

        $sections = $sections->map(function (array $section): array {
            $section['articles'] = $section['articles']->sortBy('ordre_affichage')->values();

            return $section;
        });

        $orphans = $document->articles
            ->whereNull('parent_node_id')
            ->sortBy('ordre_affichage')
            ->values();

        return [$sections, $orphans];
    }
}
