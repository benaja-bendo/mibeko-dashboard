<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\DocumentType;
use App\Http\Resources\V1\DocumentTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Document Types
 */
class DocumentTypeController extends Controller
{
    /**
     * List document types.
     */
    public function index(): AnonymousResourceCollection
    {
        return DocumentTypeResource::collection(DocumentType::all());
    }
}
