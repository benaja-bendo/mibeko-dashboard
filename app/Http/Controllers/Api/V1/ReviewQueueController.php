<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReviewQueueItemResource;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * File de revue priorisée et assignable (mibeko-front#33) : quels documents
 * traiter en premier, qui s'en occupe, pourquoi ils sont bloqués — sans
 * introduire de nouvelle machine à états : `curation_status` et
 * `curation_flags` restent la seule source de vérité.
 *
 * @group Curation
 */
class ReviewQueueController extends Controller
{
    /**
     * Liste paginée des documents en cours de curation, triée par priorité :
     * réserves bloquantes non résolues d'abord, puis les plus anciens dans
     * leur statut courant.
     *
     * Filtres : ?curation_status=draft|review|validated (défaut review),
     * ?assigned_to=me|unassigned|<uuid>.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('curation_status', LegalDocument::STATUS_REVIEW);
        $perPage = min((int) $request->input('per_page', 20), 100);
        $assignedTo = $request->query('assigned_to');

        $documents = LegalDocument::query()
            ->where('curation_status', $status)
            ->with(['assignee:id,name', 'type:code,nom'])
            ->withCount([
                'curationFlags as blocking_flags_count' => fn ($q) => $q
                    ->where('resolved', false)
                    ->where('severity', CurationFlag::SEVERITY_BLOCKING),
                'curationFlags as warning_flags_count' => fn ($q) => $q
                    ->where('resolved', false)
                    ->where('severity', CurationFlag::SEVERITY_WARNING),
            ])
            ->when($assignedTo === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($assignedTo === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($assignedTo && ! in_array($assignedTo, ['me', 'unassigned'], true), fn ($q) => $q->where('assigned_to', $assignedTo))
            // Priorité : les documents avec le plus de réserves bloquantes non
            // résolues remontent en tête, puis les plus anciens dans leur
            // statut courant. Alias `withCount` référencés nus (pas dans une
            // expression) : Postgres ne résout un nom de colonne de sortie en
            // ORDER BY que sous cette forme, sinon il le cherche en vain parmi
            // les colonnes de la table.
            ->orderByDesc('blocking_flags_count')
            ->orderBy('curation_status_changed_at')
            ->paginate($perPage);

        return $this->paginatedSuccess(
            $documents,
            ReviewQueueItemResource::class,
            'File de revue récupérée avec succès'
        );
    }

    /**
     * Prend en charge un document (auto-assignation). Idempotent si déjà pris
     * par l'appelant ; refusé (409, conflit d'édition) si un autre éditeur
     * l'a déjà pris en charge.
     */
    public function claim(string $id, Request $request): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $user = $request->user();

        if ($document->assigned_to && $document->assigned_to !== $user->id) {
            return $this->error(
                ['assigned_to' => ['Ce document est déjà pris en charge par un autre éditeur.']],
                'Conflit : document déjà assigné',
                409
            );
        }

        $document->update([
            'assigned_to' => $user->id,
            'assigned_at' => now(),
        ]);

        return $this->success(
            new ReviewQueueItemResource($document->load('assignee:id,name')),
            'Document pris en charge'
        );
    }

    /**
     * Relâche un document : par son titulaire, ou par un administrateur pour
     * débloquer une prise en charge abandonnée.
     */
    public function release(string $id, Request $request): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $user = $request->user();

        if ($document->assigned_to && $document->assigned_to !== $user->id && ! $user->hasRole('admin')) {
            return $this->error(
                ['assigned_to' => ["Seul l'éditeur en charge ou un administrateur peut relâcher ce document."]],
                'Action non autorisée',
                403
            );
        }

        $document->update([
            'assigned_to' => null,
            'assigned_at' => null,
        ]);

        return $this->success(
            new ReviewQueueItemResource($document),
            'Document relâché'
        );
    }
}
