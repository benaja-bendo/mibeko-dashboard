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
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LegalDocument::query()
            ->with(['institution', 'type']);

        if ($request->has('search')) {
            $query->where('titre_officiel', 'ilike', '%' . $request->search . '%');
        }

        if ($request->has('type')) {
            $query->where('type_code', $request->type);
        }

        if ($request->has('institution')) {
            $query->where('institution_id', $request->institution);
        }

        if ($request->has('status')) {
            $query->where('statut', $request->status);
        }

        $documents = $query->latest()
            ->paginate(20);

        return LegalDocumentResource::collection($documents);
    }

    /**
     * Get a legal document.
     * 
     * Returns a single legal document with its articles, relations, and their latest versions.
     */
    public function show(string $id): LegalDocumentResource
    {
        $document = LegalDocument::query()
            ->with(['institution', 'type', 'articles.latestVersion', 'relations.targetDocument'])
            ->findOrFail($id);

        return new LegalDocumentResource($document);
    }
}
