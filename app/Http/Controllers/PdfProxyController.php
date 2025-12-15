<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfProxyController extends Controller
{
    /**
     * Proxy a PDF from a remote URL with proper headers for inline display.
     * This solves the issue where Minio/S3 PDFs trigger downloads instead of displaying in iframes.
     */
    public function show(Request $request): StreamedResponse
    {
        $path = $request->query('path');
        $download = filter_var($request->query('download'), FILTER_VALIDATE_BOOLEAN);

        if (! $path) {
            abort(400, 'Missing path parameter');
        }

        $disk = Storage::disk('s3');

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
