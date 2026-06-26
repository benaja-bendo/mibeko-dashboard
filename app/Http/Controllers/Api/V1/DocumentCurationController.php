<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurationFlagResource;
use App\Jobs\DetectDocumentAnomalies;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Services\Curation\StructuralAnomalyDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Vue Contrôle (validation humaine) des anomalies d'un document, côté éditeur :
 * lister les signalements, (re)lancer la détection structurelle, résoudre/rouvrir.
 *
 * @group Curation
 */
class DocumentCurationController extends Controller
{
    /**
     * Liste les anomalies d'un document, les non résolues d'abord, triées par
     * sévérité (bloquantes en tête) pour piloter la correction.
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $flags = CurationFlag::query()
            ->where('document_id', $document->id)
            ->when($request->boolean('open_only'), fn ($q) => $q->where('resolved', false))
            ->with('resolver:id,name')
            ->orderBy('resolved')
            ->orderByRaw("CASE severity WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            CurationFlagResource::collection($flags),
            'Anomalies du document récupérées'
        );
    }

    /**
     * Relance la détection structurelle déterministe sur le document (idempotent).
     */
    public function detect(string $id, StructuralAnomalyDetector $detector): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $created = $detector->detect($document);

        return $this->success(
            ['created' => count($created)],
            'Détection structurelle relancée'
        );
    }

    /**
     * Lance l'analyse SÉMANTIQUE (LLM) du document : détecte les défauts de
     * contenu que les règles déterministes ne voient pas (texte tronqué,
     * articles fusionnés, charabia OCR, renvois morts…).
     *
     * Exécutée en synchrone (travail borné : feuilles suspectes, lots, plafond)
     * pour que l'éditeur voie les résultats immédiatement. À dégradation gracieuse :
     * une panne IA ne crée aucun flag et ne renvoie pas d'erreur.
     */
    public function analyzeAi(string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        DetectDocumentAnomalies::dispatchSync($document->id);

        $found = CurationFlag::where('document_id', $document->id)
            ->where('source', CurationFlag::SOURCE_LLM)
            ->where('resolved', false)
            ->count();

        return $this->success(['found' => $found], 'Analyse IA terminée');
    }

    /**
     * Résout (ou rouvre) une anomalie, avec traçabilité.
     */
    public function update(Request $request, CurationFlag $flag): JsonResponse
    {
        $document = LegalDocument::findOrFail($flag->document_id);
        Gate::authorize('update', $document);

        $resolved = $request->validate([
            'resolved' => ['required', 'boolean'],
        ])['resolved'];

        $flag->update([
            'resolved' => $resolved,
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $request->user()->id : null,
        ]);

        return $this->success(
            new CurationFlagResource($flag->load('resolver:id,name')),
            $resolved ? 'Anomalie résolue' : 'Anomalie rouverte'
        );
    }
}
