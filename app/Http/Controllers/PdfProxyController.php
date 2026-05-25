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
            $mediaFile = $document->mediaFiles->firstWhere('mime_type', 'application/pdf')
                ?? $document->mediaFiles->first(fn ($file) => str_ends_with(strtolower((string) $file->file_path), '.pdf'));
            $path = $mediaFile?->file_path;
        }

        $download = filter_var($request->query('download'), FILTER_VALIDATE_BOOLEAN);

        if (! $path) {
            abort(404, 'No source PDF available for this document');
        }

        // Remove the s3:// bucket prefix if it exists because the s3 disk is already configured for this bucket
        $cleanPath = $path;
        if (str_starts_with($cleanPath, 's3://')) {
            // Extracts everything after s3://bucket-name/
            $parts = explode('/', $cleanPath);
            if (count($parts) >= 4) { // s3:, "", bucket-name, path...
                $cleanPath = implode('/', array_slice($parts, 3));
            }
        }

        $diskName = $mediaFile?->storage_provider === 'MINIO' || str_starts_with($path, 's3://') || str_starts_with($path, 'documents/')
            ? 's3'
            : config('filesystems.default', 'local');

        $disk = Storage::disk($diskName);

        try {
            if (! $disk->exists($cleanPath)) {
                abort(404, 'File not found in storage');
            }
        } catch (\Throwable $e) {
            abort(404, 'File not accessible: '.$e->getMessage());
        }

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
            'X-Frame-Options' => 'SAMEORIGIN',
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
