<?php

namespace App\Http\Controllers;

use App\Http\Resources\LegalDocumentResource;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = LegalDocument::query()
            ->with(['type', 'institution'])
            ->when($request->search, function ($query, $search) {
                $query->where('titre_officiel', 'like', "%{$search}%")
                    ->orWhere('reference_nor', 'like', "%{$search}%");
            })
            ->when($request->type, function ($query, $type) {
                $query->where('type_code', $type);
            })
            ->when($request->institution, function ($query, $institution) {
                $query->where('institution_id', $institution);
            })
            ->when($request->status, function ($query, $status) {
                $query->where('statut', $status);
            })
            ->latest('date_publication');

        $documents = $query->paginate(15)->withQueryString();

        return Inertia::render('documents/index', [
            'documents' => LegalDocumentResource::collection($documents),
            'filters' => $request->only(['search', 'type', 'institution', 'status']),
            'types' => \App\Models\DocumentType::all(),
            'institutions' => \App\Models\Institution::all(),
        ]);
    }

    public function show(LegalDocument $document): Response
    {
        $document->load([
            'type',
            'institution',
            'structureNodes',
            'articles.versions' => function ($query) {
                $query->orderBy('valid_from', 'desc');
            },
            'articles.parentNode',
        ]);

        return Inertia::render('documents/show', [
            'document' => new LegalDocumentResource($document),
        ]);
    }
}
