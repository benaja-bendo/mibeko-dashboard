<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * List files available in the S3 bucket (sources/ directory).
     */
    public function listAvailableFiles(Request $request)
    {
        $files = Storage::disk('s3')->files('sources');

        return response()->json([
            'files' => array_map(function ($file) {
                return [
                    'path' => $file,
                    'name' => basename($file),
                    'size' => Storage::disk('s3')->size($file),
                    'last_modified' => Storage::disk('s3')->lastModified($file),
                ];
            }, $files)
        ]);
    }

    /**
     * Attach a file from S3 to a document.
     */
    public function attachFile(Request $request, LegalDocument $document)
    {
        $validated = $request->validate([
            'file_path' => 'required|string',
        ]);

        if (!Storage::disk('s3')->exists($validated['file_path'])) {
            return response()->json(['message' => 'Fichier introuvable sur le stockage.'], 404);
        }

        // Delete existing media files if any (optional, depends on if we want multiple)
        $document->mediaFiles()->delete();

        $document->mediaFiles()->create([
            'file_path' => $validated['file_path'],
            'mime_type' => 'application/pdf',
            'description' => 'Attaché manuellement depuis le navigateur',
        ]);

        return back()->with('success', 'Fichier attaché avec succès.');
    }
}
