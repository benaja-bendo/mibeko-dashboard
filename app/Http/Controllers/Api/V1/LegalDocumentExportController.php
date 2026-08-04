<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LegalDocument;
use App\Services\DocumentExportPdfService;
use App\Traits\GuardsUnpublishedDocuments;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalDocumentExportController extends Controller
{
    use GuardsUnpublishedDocuments;

    /**
     * Export a full legal document to PDF.
     *
     * Generates a high-quality PDF version of the complete document including all its articles and structure.
     * Un document non publié n'est exportable que par un éditeur/admin (404 sinon).
     *
     * Le rendu (coûteux sur un gros document) est mis en cache par version
     * — voir {@see DocumentExportPdfService} — et normalement pré-chauffé en
     * tâche de fond à la publication ; cet endpoint ne rend en synchrone que
     * si aucun cache n'existe encore pour la version courante.
     *
     * @param  string  $id  The UUID of the legal document.
     *
     * @response 200 binary The generated PDF file.
     */
    public function export(Request $request, string $id, DocumentExportPdfService $exporter): StreamedResponse
    {
        $document = LegalDocument::query()
            ->when(! $this->canViewUnpublishedDocuments($request), fn ($q) => $q->published())
            ->findOrFail($id);

        return $exporter->respond($document);
    }

    /**
     * Export a single article to PDF.
     *
     * Generates a PDF version of a specific article with its metadata and parent document info.
     * L'article d'un document non publié n'est exportable que par un éditeur/admin (404 sinon).
     *
     * @param  string  $id  The UUID of the article.
     *
     * @response 200 binary The generated PDF file.
     */
    public function exportArticle(Request $request, string $id): Response
    {
        ini_set('memory_limit', '256M');
        $article = Article::query()
            ->with(['document', 'document.institution', 'document.type', 'parentNode', 'activeVersion'])
            ->findOrFail($id);

        $document = $article->document;

        $this->ensureDocumentIsVisible($request, $document);

        $pdf = Pdf::loadView('documents.pro_article_pdf', compact('article', 'document'));
        $pdf->setPaper('a4');

        $filename = 'Article-'.$article->numero_article.'-'.Str::slug($document->titre_officiel ?? 'document').'.pdf';

        return $pdf->download($filename);
    }
}
