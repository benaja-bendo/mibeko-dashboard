<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\LegalDocument;
use App\Http\Resources\V1\LegalDocumentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Legal Documents
 * 
 * API endpoints for managing legal documents.
 */
class LegalDocumentController extends Controller
{
    /**
     * List legal documents.
     * 
     * Returns a paginated list of legal documents with their institutions and types.
     */
    public function index(): AnonymousResourceCollection
    {
        $documents = LegalDocument::query()
            ->with(['institution', 'type'])
            ->latest()
            ->paginate(20);

        return LegalDocumentResource::collection($documents);
    }

    /**
     * Get a legal document.
     * 
     * Returns a single legal document with its articles and their latest versions.
     */
    public function show(string $id): LegalDocumentResource
    {
        $document = LegalDocument::query()
            ->with(['institution', 'type', 'articles.latestVersion'])
            ->findOrFail($id);

        return new LegalDocumentResource($document);
    }
}
