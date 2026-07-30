<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Traits\GuardsUnpublishedDocuments;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class DossierExportController extends Controller
{
    use GuardsUnpublishedDocuments;

    /**
     * Generate a PDF export of a dossier.
     * Expects a JSON body with:
     * - title: string
     * - description: string (optional)
     * - items: array of objects { type: 'article'|'document', id: string, note: string|null }
     */
    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'items' => 'required|array',
            'items.*.type' => 'required|in:article,document',
            'items.*.id' => 'required|uuid',
            'items.*.note' => 'nullable|string',
        ]);

        $title = $validated['title'];
        $description = $validated['description'] ?? '';
        $items = collect($validated['items']);

        // Group items by type to batch fetch
        $articleIds = $items->where('type', 'article')->pluck('id');
        // $documentIds = $items->where('type', 'document')->pluck('id'); // Future support

        $articlesQuery = Article::with(['activeVersion', 'latestVersion', 'document', 'parentNode'])
            ->whereIn('id', $articleIds);

        // Les ids d'articles arrivent librement dans le corps de la requête :
        // sans ce filtre, n'importe quel compte pourrait exfiltrer en PDF des
        // articles de documents non publiés (brouillons, en revue). Seuls les
        // rôles éditoriaux voient le corpus complet, comme sur les endpoints
        // de lecture publics (cf. GuardsUnpublishedDocuments).
        if (! $this->canViewUnpublishedDocuments($request)) {
            $articlesQuery->whereHas('document', fn ($query) => $query->published());
        }

        $articles = $articlesQuery->get()->keyBy('id');

        // Prepare data for view keeping the order requested
        $exportItems = $items->map(function ($item) use ($articles) {
            if ($item['type'] === 'article') {
                $article = $articles->get($item['id']);
                if (! $article) {
                    return null;
                }

                return [
                    'type' => 'article',
                    'content' => $article,
                    'note' => $item['note'] ?? null,
                ];
            }

            // Add document handling here later
            return null;
        })->filter();

        if (ob_get_length()) {
            ob_end_clean();
        }
        $pdf = Pdf::loadView('exports.pro_dossier_pdf', [
            'title' => $title,
            'description' => $description,
            'items' => $exportItems,
            'generated_at' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);
        // Le gabarit n'embarque aucune ressource distante et tout le contenu
        // utilisateur y est échappé : on interdit à DomPDF toute requête
        // sortante (anti-SSRF/exfiltration via contenu injecté).
        $pdf->setOption('isRemoteEnabled', false);

        // Return raw PDF bytes for API consumption (mobile apps)
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->slugify($title).'.pdf"',
        ]);
    }

    private function slugify($text)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text)));
    }
}
