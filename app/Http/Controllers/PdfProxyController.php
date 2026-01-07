<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfProxyController extends Controller
{
    /**
     * Proxy a PDF from the document's source URL (MinIO/S3).
     * This ensures the mobile app gets the file via the API without exposing direct S3 links.
     */
    public function show(Request $request, string $id): StreamedResponse
    {
        $document = LegalDocument::findOrFail($id);
        $path = $document->source_url;
        $download = filter_var($request->query('download'), FILTER_VALIDATE_BOOLEAN);

        if (! $path) {
            abort(404, 'No source PDF available for this document');
        }

        $disk = Storage::disk('s3');

        if (! $disk->exists($path)) {
            abort(404, 'File not found in storage');
        }

        $isPdf = str_ends_with(strtolower($path), '.pdf');
        $contentType = $isPdf ? 'application/pdf' : 'application/octet-stream';
        $filename = basename($path);
        
        // Si le nom du fichier est un UUID obscur, on peut proposer un nom plus lisible
        // $filename = Str::slug($document->titre_officiel) . '.pdf';

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => $download ? ('attachment; filename="'.$filename.'"') : 'inline',
            'Content-Length' => $disk->size($path),
            'Cache-Control' => 'public, max-age=3600',
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
