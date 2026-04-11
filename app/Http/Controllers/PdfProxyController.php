<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
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
    public function show(Request $request, string $id): StreamedResponse
    {
        $type = $request->query('type', 'document'); // 'document' or 'journal'

        $path = null;

        if ($type === 'journal') {
            $journal = OfficialJournal::findOrFail($id);
            $path = $journal->file_path;
        } else {
            $document = LegalDocument::with('mediaFiles')->findOrFail($id);
            $mediaFile = $document->mediaFiles->first();
            $path = $mediaFile?->file_path;
        }

        $download = filter_var($request->query('download'), FILTER_VALIDATE_BOOLEAN);

        if (! $path) {
            abort(404, 'No source PDF available for this document');
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));

        if (! $disk->exists($path)) {
            abort(404, 'File not found in storage');
        }

        $isPdf = str_ends_with(strtolower($path), '.pdf');
        $contentType = $isPdf ? 'application/pdf' : 'application/octet-stream';
        $filename = basename($path);

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => $download ? ('attachment; filename="'.$filename.'"') : 'inline',
            'Content-Length' => $disk->size($path),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Accept-Ranges' => 'bytes',
        ];

        return response()->stream(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            $headers
        );
    }
}
