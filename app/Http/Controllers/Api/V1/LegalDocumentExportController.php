<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\LegalDocument;
use App\Models\Article;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class LegalDocumentExportController extends Controller
{
    /**
     * Export a full legal document to PDF.
     *
     * Generates a high-quality PDF version of the complete document including all its articles and structure.
     *
     * @param string $id The UUID of the legal document.
     * @response 200 binary The generated PDF file.
     */
    public function export(string $id): Response
    {
        // Increase timeout for large documents
        set_time_limit(300);

        $document = LegalDocument::query()
            ->with([
                'institution',
                'type',
                'structureNodes' => function($q) {
                    $q->orderBy('sort_order');
                },
                'articles' => function($q) {
                    $q->orderBy('ordre_affichage');
                },
                'articles.activeVersion',
            ])
            ->findOrFail($id);

        $pdf = Pdf::loadView('documents.pdf', compact('document'));

        $filename = \Illuminate\Support\Str::slug($document->titre_officiel ?? 'document') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Export a single article to PDF.
     *
     * Generates a PDF version of a specific article with its metadata and parent document info.
     *
     * @param string $id The UUID of the article.
     * @response 200 binary The generated PDF file.
     */
    public function exportArticle(string $id): Response
    {
        $article = Article::query()
            ->with(['document', 'document.institution', 'document.type', 'parentNode', 'activeVersion'])
            ->findOrFail($id);

        $document = $article->document;

        $pdf = Pdf::loadView('documents.article_pdf', compact('article', 'document'));

        $filename = 'Article-' . $article->numero_article . '-' . \Illuminate\Support\Str::slug($document->titre_officiel ?? 'document') . '.pdf';

        return $pdf->download($filename);
    }
}
