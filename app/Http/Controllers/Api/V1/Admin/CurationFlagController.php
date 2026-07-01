<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkCurationFlagRequest;
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
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->query('source')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->query('severity')))
            ->when($request->filled('document_id'), fn ($q) => $q->where('document_id', $request->query('document_id')))
            // Bloquantes d'abord, puis les plus récentes : on traite le critique en tête.
            ->orderByRaw("CASE severity WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
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

    /**
     * Action groupée sur une sélection de signalements : résoudre, ré-ouvrir
     * ou supprimer plusieurs signalements en une seule requête.
     *
     * Renvoie `{ affected: <n> }`, le nombre de signalements réellement touchés
     * (les identifiants déjà disparus sont ignorés).
     */
    public function bulk(BulkCurationFlagRequest $request): JsonResponse
    {
        $ids = $request->validated('ids');
        $action = $request->validated('action');

        if ($action === 'delete') {
            $affected = CurationFlag::whereIn('id', $ids)->delete();

            return $this->success(
                ['affected' => $affected],
                trans_choice('{0}Aucun signalement supprimé|{1}1 signalement supprimé|[2,*]:count signalements supprimés', $affected, ['count' => $affected])
            );
        }

        $resolved = $action === 'resolve';

        $affected = CurationFlag::whereIn('id', $ids)->update([
            'resolved' => $resolved,
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $request->user()->id : null,
        ]);

        $message = $resolved
            ? trans_choice('{0}Aucun signalement résolu|{1}1 signalement résolu|[2,*]:count signalements résolus', $affected, ['count' => $affected])
            : trans_choice('{0}Aucun signalement ré-ouvert|{1}1 signalement ré-ouvert|[2,*]:count signalements ré-ouverts', $affected, ['count' => $affected]);

        return $this->success(['affected' => $affected], $message);
    }
}
