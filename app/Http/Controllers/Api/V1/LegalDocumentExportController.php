<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\LegalDocument;

class LegalDocumentExportController extends Controller
{
    /**
     * Export a full legal document with its structure and articles.
     */
    public function export(string $id): LegalDocumentResource
    {
        $document = LegalDocument::query()
            ->with([
                'institution',
                'type',
                'structureNodes',
                'articles.activeVersion',
                'articles.tags',
                'relations',
            ])
            ->findOrFail($id);

        return LegalDocumentResource::make($document);
    }
}
