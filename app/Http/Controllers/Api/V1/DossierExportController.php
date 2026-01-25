<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class DossierExportController extends Controller
{
    /**
     * Generate a PDF export of a dossier.
     * Expects a JSON body with:
     * - title: string
     * - description: string (optional)
     * - items: array of objects { type: 'article'|'document', id: string, note: string|null }
     */
    public function exportPdf(Request $request)
    {
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

        $articles = Article::with(['activeVersion', 'document', 'parentNode.parent'])
            ->whereIn('id', $articleIds)
            ->get()
            ->keyBy('id');

        // Prepare data for view keeping the order requested
        $exportItems = $items->map(function ($item) use ($articles) {
            if ($item['type'] === 'article') {
                $article = $articles->get($item['id']);
                if (!$article) return null;
                
                return [
                    'type' => 'article',
                    'content' => $article,
                    'note' => $item['note'] ?? null,
                ];
            }
            // Add document handling here later
            return null;
        })->filter();

        $pdf = Pdf::loadView('exports.dossier_pdf', [
            'title' => $title,
            'description' => $description,
            'items' => $exportItems,
            'generated_at' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download($this->slugify($title) . '.pdf');
    }

    private function slugify($text)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text)));
    }
}
