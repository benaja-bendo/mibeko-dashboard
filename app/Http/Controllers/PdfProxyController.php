<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Services\SourcePdfResolver;
use App\Traits\GuardsUnpublishedDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Media
 *
 * Proxy endpoints for secure media access.
 */
class PdfProxyController extends Controller
{
    use GuardsUnpublishedDocuments;

    /**
     * Download or view a PDF.
     *
     * Proxy a PDF from the document's source URL (MinIO/S3).
     * This ensures the mobile app gets the file via the API without exposing direct S3 links.
     *
     * @urlParam id string required The ID of the document or journal.
     *
     * @queryParam type string Optional. The type of resource ('document' or 'journal'). Defaults to 'document'.
     * @queryParam download boolean Optional. Set to 'true' to force a download instead of inline viewing.
     *
     * @response 200 {"content": "Binary PDF Data"}
     */
    public function show(Request $request, string $id, SourcePdfResolver $pdfResolver): StreamedResponse
    {
        $type = $request->query('type', 'document'); // 'document' or 'journal'

        if ($type === 'journal') {
            $journal = OfficialJournal::findOrFail($id);

            // Un JO publié est public par nature. Un JO NON publié, en revanche,
            // porte le PDF source d'actes encore en brouillon : le servir ici
            // contournerait la garde posée sur les documents (404, pas 403, pour
            // ne pas révéler l'existence de la ressource).
            abort_unless($journal->is_published, 404);

            $sourcePdf = $pdfResolver->forOfficialJournal($journal);
        } else {
            $document = LegalDocument::with(['mediaFiles', 'officialJournal'])->findOrFail($id);

            // Route publique : le PDF source d'un document non publié reste
            // réservé aux éditeurs/admins (404 sinon). Les journaux officiels
            // (`type=journal`) suivent la même logique via leur `is_published`.
            $this->ensureDocumentIsVisible($request, $document);

            $sourcePdf = $pdfResolver->forDocument($document);
        }

        $download = filter_var($request->query('download'), FILTER_VALIDATE_BOOLEAN);

        if ($sourcePdf === null) {
            abort(404, 'No source PDF available for this document');
        }

        $cleanPath = $sourcePdf->objectKey;
        $disk = Storage::disk($sourcePdf->diskName);

        $isPdf = str_ends_with(strtolower($cleanPath), '.pdf');
        $contentType = $isPdf ? 'application/pdf' : 'application/octet-stream';
        $filename = basename($cleanPath);

        $fileSize = $disk->size($cleanPath);

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => $download ? ('attachment; filename="'.$filename.'"') : 'inline',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            // Autorise l'intégration du PDF dans une <iframe> du site vitrine
            // (origine distincte de l'API). `frame-ancestors` remplace
            // `X-Frame-Options: SAMEORIGIN` qui bloquait le cross-origin.
            'Content-Security-Policy' => "frame-ancestors 'self' ".config('app.pdf_frame_ancestors', 'https://mibeko.fr https://www.mibeko.fr http://localhost:4321 http://localhost:4377'),
            'X-Accel-Buffering' => 'no', // Disable proxy buffering (Nginx)
        ];

        $range = $request->header('Range');
        $start = 0;
        $end = $fileSize - 1;
        $status = 200;

        if ($range && str_starts_with($range, 'bytes=')) {
            $status = 206;
            $rangeParts = explode('-', substr($range, 6));
            $start = (int) $rangeParts[0];
            if (isset($rangeParts[1]) && is_numeric($rangeParts[1])) {
                $end = (int) $rangeParts[1];
            }

            $headers['Content-Range'] = "bytes $start-$end/$fileSize";
            $headers['Content-Length'] = ($end - $start) + 1;
        } else {
            $headers['Content-Length'] = $fileSize;
        }

        return response()->stream(
            function () use ($disk, $cleanPath, $start, $end) {
                try {
                    $stream = $disk->readStream($cleanPath);
                    if (! is_resource($stream)) {
                        return;
                    }

                    if ($start > 0) {
                        fseek($stream, $start);
                    }

                    $remaining = ($end - $start) + 1;
                    $chunkSize = 8192;

                    while ($remaining > 0 && ! feof($stream)) {
                        $toRead = min($remaining, $chunkSize);
                        echo fread($stream, $toRead);
                        $remaining -= $toRead;
                        flush();
                    }

                    fclose($stream);
                } catch (\Throwable) {
                }
            },
            $status,
            $headers
        );
    }
}
