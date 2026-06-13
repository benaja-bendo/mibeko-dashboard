<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OfficialJournalResource;
use App\Models\OfficialJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Official Journals
 *
 * API endpoints for listing, retrieving and managing official journals.
 */
class OfficialJournalController extends Controller
{
    /**
     * Indique si l'utilisateur courant peut administrer les journaux.
     *
     * Les routes de lecture sont publiques (bibliothèque web + mobile) : la
     * résolution passe explicitement par le garde sanctum, qui renvoie null
     * sans token.
     */
    private function isManager(Request $request): bool
    {
        return (bool) $request->user('sanctum')?->hasAnyRole(['editor', 'admin']);
    }

    /**
     * List official journals.
     *
     * Returns a paginated list of published official journals. Editors and
     * admins may pass `include_unpublished=1` to retrieve every journal with
     * full counters for the management screen. The public payload keeps
     * `legal_documents_count` scoped to published documents (mobile contract).
     *
     * @queryParam filter[number] string Filter by official journal number.
     * @queryParam filter[year] integer Filter by publication year.
     * @queryParam filter[title] string Filter by partial title (managers).
     * @queryParam filter[transcription_status] string Filter by transcription status (managers).
     * @queryParam filter[is_published] boolean Filter by visibility (managers).
     * @queryParam include_unpublished boolean Include unpublished journals (managers only).
     * @queryParam sort string Sort field (e.g., "publication_date", "-created_at"). Defaults to "-publication_date".
     *
     * @return AnonymousResourceCollection<OfficialJournalResource>
     */
    public function index(Request $request)
    {
        $managerView = $this->isManager($request) && $request->boolean('include_unpublished');

        $publishedCount = function (Builder $query) {
            $query->where('curation_status', 'published');
        };

        $query = QueryBuilder::for(OfficialJournal::class)
            ->withCount($managerView
                ? ['legalDocuments', 'legalDocuments as published_legal_documents_count' => $publishedCount]
                : ['legalDocuments' => $publishedCount])
            ->allowedFilters([
                'number',
                AllowedFilter::partial('title'),
                AllowedFilter::callback('year', function (Builder $query, $value) {
                    $query->whereYear('publication_date', $value);
                }),
                AllowedFilter::exact('transcription_status'),
                AllowedFilter::exact('is_published'),
            ])
            ->allowedSorts(['publication_date', 'created_at', 'number'])
            ->defaultSort('-publication_date');

        if (! $managerView) {
            $query->where('is_published', true);
        }

        $journals = $query->paginate(min((int) $request->get('per_page', 15), 100));

        return OfficialJournalResource::collection($journals);
    }

    /**
     * List the publication years of published journals.
     *
     * Powers the year navigation of the Pro kiosk: distinct years with the
     * number of published journals for each, most recent first.
     */
    public function years(): JsonResponse
    {
        $years = OfficialJournal::query()
            ->where('is_published', true)
            ->whereNotNull('publication_date')
            ->selectRaw('EXTRACT(YEAR FROM publication_date)::int AS year, COUNT(*) AS total')
            ->groupBy('year')
            ->orderByDesc('year')
            ->get()
            ->map(fn ($row) => ['year' => (int) $row->year, 'total' => (int) $row->total]);

        return $this->success($years, 'Années de publication récupérées avec succès');
    }

    /**
     * Get an official journal.
     *
     * Returns a specific published official journal with its associated legal
     * documents. Editors and admins may pass `include_unpublished=1` to read
     * any journal with all attached documents regardless of curation status.
     * The response includes a `pdf_url` pointing to the PDF proxy endpoint.
     *
     * @urlParam id string required The ID of the official journal.
     */
    public function show(Request $request, string $id): OfficialJournalResource
    {
        $managerView = $this->isManager($request) && $request->boolean('include_unpublished');

        $journal = OfficialJournal::with(['legalDocuments' => function ($query) use ($managerView) {
            if (! $managerView) {
                $query->where('curation_status', 'published');
            }
            $query->withCount('articles')->orderBy('created_at');
        }])
            ->when(! $managerView, fn ($query) => $query->where('is_published', true))
            ->findOrFail($id);

        // Utilize the app's internal PDF proxy instead of raw MinIO signed URL
        // This ensures consistent access rules, metrics capability, and avoids expiration issues for the client if needed.
        if ($journal->file_path) {
            $journal->pdf_url = url("/api/v1/legal-documents/{$journal->id}/pdf?type=journal");
        }

        return new OfficialJournalResource($journal);
    }

    /**
     * Update an official journal (editor + admin only).
     *
     * Edits journal metadata and its visibility in the public library.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $journal = OfficialJournal::findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'publication_date' => ['sometimes', 'nullable', 'date'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $journal->update($validated);

        return $this->success(
            new OfficialJournalResource($journal->fresh()->loadCount('legalDocuments')),
            'Journal officiel mis à jour avec succès'
        );
    }

    /**
     * Delete an official journal (admin only).
     *
     * Soft-deletes the journal. Attached documents are kept but detached so
     * they remain manageable from the documents catalog.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasRole('admin')) {
            return $this->error(null, 'Seul un administrateur peut supprimer un journal officiel.', 403);
        }

        $journal = OfficialJournal::findOrFail($id);

        $journal->legalDocuments()->update(['official_journal_id' => null]);
        $journal->delete();

        return $this->success(null, 'Journal officiel supprimé avec succès');
    }
}
