<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OfficialJournalResource;
use App\Models\OfficialJournal;
use App\Services\OfficialJournalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Official Journals
 *
 * API endpoints for listing and retrieving official journals.
 */
class OfficialJournalController extends Controller
{
    public function __construct(protected OfficialJournalService $journalService) {}

    /**
     * List official journals.
     *
     * Returns a paginated list of published official journals.
     * Supports sorting via query parameters.
     *
     * @queryParam sort string Sort field (e.g., "publication_date", "-created_at"). Defaults to "-publication_date".
     *
     * @return AnonymousResourceCollection<OfficialJournalResource>
     */
    public function index(Request $request)
    {
        $journals = QueryBuilder::for(OfficialJournal::class)
            ->where('is_published', true)
            ->allowedSorts(['publication_date', 'created_at'])
            ->defaultSort('-publication_date')
            ->paginate($request->get('per_page', 15));

        return OfficialJournalResource::collection($journals);
    }

    /**
     * Get an official journal.
     *
     * Returns a specific published official journal with its associated legal documents.
     * The response will include a `pdf_url` which is an absolute URL pointing to the PDF proxy endpoint.
     *
     * @urlParam id string required The ID of the official journal.
     */
    public function show(string $id): OfficialJournalResource
    {
        $journal = OfficialJournal::with(['legalDocuments' => function ($query) {
            $query->where('curation_status', 'published');
        }])
            ->where('is_published', true)
            ->findOrFail($id);

        // Utilize the app's internal PDF proxy instead of raw MinIO signed URL
        // This ensures consistent access rules, metrics capability, and avoids expiration issues for the client if needed.
        if ($journal->file_path) {
            $journal->pdf_url = url("/api/v1/legal-documents/{$journal->id}/pdf?type=journal");
        }

        return new OfficialJournalResource($journal);
    }
}
