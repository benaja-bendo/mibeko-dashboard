<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateCurationFlagRequest;
use App\Http\Resources\V1\Admin\CurationFlagResource;
use App\Models\CurationFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Triage des signalements (CurationFlag) émis depuis les apps clientes.
 *
 * @group Admin / Signalements
 */
class CurationFlagController extends Controller
{
    /**
     * Liste paginée des signalements, du plus récent au plus ancien.
     *
     * Filtres : ?status=open|resolved|all (défaut open), ?type=<type_probleme>.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');

        $flags = CurationFlag::query()
            ->with([
                'document:id,titre_officiel',
                'article:id,document_id,numero_article',
                'article.document:id,titre_officiel',
                'resolver:id,name',
            ])
            ->when($status === 'open', fn ($q) => $q->where('resolved', false))
            ->when($status === 'resolved', fn ($q) => $q->where('resolved', true))
            ->when($request->filled('type'), fn ($q) => $q->where('type_probleme', $request->query('type')))
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return $this->paginatedSuccess(
            $flags,
            CurationFlagResource::class,
            'Signalements récupérés avec succès'
        );
    }

    /**
     * Marque un signalement comme résolu (avec traçabilité) ou le ré-ouvre.
     */
    public function update(UpdateCurationFlagRequest $request, CurationFlag $flag): JsonResponse
    {
        $resolved = $request->validated('resolved');

        $flag->update([
            'resolved' => $resolved,
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $request->user()->id : null,
        ]);

        $flag->load([
            'document:id,titre_officiel',
            'article:id,document_id,numero_article',
            'article.document:id,titre_officiel',
            'resolver:id,name',
        ]);

        return $this->success(
            new CurationFlagResource($flag),
            $resolved ? 'Signalement résolu' : 'Signalement ré-ouvert'
        );
    }

    /**
     * Supprime un signalement (ex : doublon ou hors-sujet).
     */
    public function destroy(CurationFlag $flag): JsonResponse
    {
        $flag->delete();

        return $this->success(null, 'Signalement supprimé avec succès');
    }
}
