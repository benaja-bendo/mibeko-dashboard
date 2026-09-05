<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LegalDocument;
use App\Services\DocumentExportPdfService;
use App\Services\EntitlementsResolver;
use App\Traits\GuardsUnpublishedDocuments;
use App\Traits\HttpResponses;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalDocumentExportController extends Controller
{
    use GuardsUnpublishedDocuments, HttpResponses;

    /**
     * Durée de vie du jeton d'export signé (mibeko-dashboard#86) : assez
     * courte pour rester une capacité ponctuelle, assez large pour couvrir
     * la latence d'un réseau dégradé entre le mint et le clic qui suit.
     */
    private const SIGNED_URL_TTL_SECONDS = 120;

    public function __construct(private readonly EntitlementsResolver $entitlements) {}

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

    /**
     * Mint une URL signée à courte durée de vie pour l'export PDF d'un
     * document — mibeko-dashboard#86. Le clic direct `<a href>` du lecteur
     * Bibliothèque ne porte aucun jeton Bearer : cet endpoint (lui,
     * authentifié) vérifie l'entitlement une fois, puis délègue la preuve
     * à la signature de l'URL qu'il renvoie.
     */
    public function mintDocumentToken(Request $request, string $id): JsonResponse
    {
        return $this->mintSignedUrl($request, 'legal-documents.export.signed', $id);
    }

    /**
     * Idem pour l'export d'un article seul (consommé par l'app mobile).
     */
    public function mintArticleToken(Request $request, string $id): JsonResponse
    {
        return $this->mintSignedUrl($request, 'articles.export.signed', $id);
    }

    private function mintSignedUrl(Request $request, string $routeName, string $id): JsonResponse
    {
        abort_unless(
            $this->entitlements->resolve($request->user())['features']['export'],
            403,
            "L'export PDF Mibeko est réservé aux comptes Pro."
        );

        return $this->success([
            'url' => URL::temporarySignedRoute(
                $routeName,
                now()->addSeconds(self::SIGNED_URL_TTL_SECONDS),
                ['id' => $id],
            ),
        ]);
    }
}
