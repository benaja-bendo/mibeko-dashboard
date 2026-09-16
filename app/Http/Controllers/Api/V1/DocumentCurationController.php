<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurationFlagResource;
use App\Http\Resources\V1\PublicationChecklistResource;
use App\Jobs\DetectDocumentAnomalies;
use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\DocumentRelecturePreuve;
use App\Models\LegalDocument;
use App\Services\Curation\RelectureDirigeeService;
use App\Services\Curation\StructuralAnomalyDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            ->when($request->filled('type_probleme'), fn ($q) => $q->where('type_probleme', $request->query('type_probleme')))
            ->with(['resolver:id,name', 'creator:id,name'])
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
     * Résout (ou rouvre) une anomalie, avec traçabilité. Permet aussi de
     * requalifier sa sévérité (ex : un signalement public confirmé comme
     * bloquant, ou à l'inverse rétrogradé).
     */
    public function update(Request $request, CurationFlag $flag): JsonResponse
    {
        $document = LegalDocument::findOrFail($flag->document_id);
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'resolved' => ['required', 'boolean'],
            'severity' => ['sometimes', 'string', Rule::in([
                CurationFlag::SEVERITY_BLOCKING,
                CurationFlag::SEVERITY_WARNING,
                CurationFlag::SEVERITY_INFO,
            ])],
        ]);

        $resolved = $validated['resolved'];
        $severity = $validated['severity'] ?? null;

        if ($severity !== null && $severity !== $flag->severity) {
            Log::info('Signalement requalifié au triage.', [
                'flag_id' => $flag->id,
                'from' => $flag->severity,
                'to' => $severity,
                'admin_id' => $request->user()->id,
            ]);
        }

        $flag->update([
            'resolved' => $resolved,
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? $request->user()->id : null,
            ...($severity !== null ? ['severity' => $severity] : []),
        ]);

        return $this->success(
            new CurationFlagResource($flag->load('resolver:id,name')),
            $resolved ? 'Anomalie résolue' : 'Anomalie rouverte'
        );
    }

    /**
     * Transmet une demande de correction tracée depuis la file de revue
     * (mibeko-front#33) : crée un signalement humain, bloquant par défaut,
     * qui suit exactement le même cycle que les autres réserves — résolu via
     * `update()` ci-dessus, et il bloque déjà la publication (garde-fou de
     * `LegalDocumentController::update()`) tant qu'il reste ouvert.
     */
    public function requestCorrection(Request $request, string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:5000'],
            'severity' => ['sometimes', 'string', Rule::in([
                CurationFlag::SEVERITY_BLOCKING,
                CurationFlag::SEVERITY_WARNING,
            ])],
        ]);

        $flag = CurationFlag::create([
            'document_id' => $document->id,
            'source' => CurationFlag::SOURCE_HUMAN,
            'type_probleme' => 'correction_demandee',
            'severity' => $validated['severity'] ?? CurationFlag::SEVERITY_BLOCKING,
            'description' => $validated['description'],
            'created_by' => $request->user()->id,
            'resolved' => false,
        ]);

        return $this->success(
            new CurationFlagResource($flag->load('creator:id,name')),
            'Demande de correction transmise',
            201
        );
    }

    /**
     * Historique des preuves de validation (dashboard#119) : chaque passage
     * du garde-fou de publication sur ce document, bloqué ou réussi, du plus
     * récent au plus ancien — de quoi répondre après coup à « qui a validé
     * quoi, quand, et sur quelle version du document ».
     */
    public function publicationChecklists(Request $request, string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $checklists = $document->publicationChecklists()
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', 20), 100));

        return $this->paginatedSuccess(
            $checklists,
            PublicationChecklistResource::class,
            'Preuves de validation récupérées'
        );
    }

    /**
     * Exigences de relecture dirigée pour ce document (mibeko-dashboard#142,
     * étape 4 du protocole de validation) : points d'observation obligatoires
     * et sondage, calculés côté serveur — le front (mibeko-front#44) ne
     * recalcule rien, il affiche ceci et confirme. `preuve_existante` dit si
     * une relecture couvrant le run ACTUEL a déjà été enregistrée (auquel cas
     * la publication n'est déjà plus bloquée par ce critère).
     */
    public function relectureRequise(string $id, RelectureDirigeeService $service): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $dernierRun = $document->controleRuns()->orderByDesc('date')->orderByDesc('id')->first();

        $articleLabel = function (array $ids) {
            return Article::whereIn('id', $ids)->get(['id', 'numero_article'])
                ->map(fn (Article $article) => ['id' => $article->id, 'numero_article' => $article->numero_article])
                ->values();
        };

        $preuveExistante = $dernierRun !== null
            ? $document->relecturePreuves()->where('document_controle_run_id', $dernierRun->id)->latest('created_at')->first()
            : null;

        return $this->success([
            'document_controle_run_id' => $dernierRun?->id,
            'version_jeu' => $dernierRun?->version_jeu,
            'resultat' => $dernierRun?->resultat,
            'points_obligatoires' => $articleLabel($service->pointsObligatoires($document)->all()),
            'sondage_articles' => $articleLabel(
                $dernierRun !== null ? $service->sondage($document, $dernierRun->version_jeu)->all() : []
            ),
            'preuve_existante' => $preuveExistante !== null,
        ], 'Exigences de relecture dirigée calculées');
    }

    /**
     * Enregistre une preuve de relecture dirigée. Ne fait JAMAIS confiance au
     * client sur ce qui était exigé : les points obligatoires et le sondage
     * sont recalculés ici, comme dans `relectureRequise()`, et la preuve
     * n'est acceptée que si elle les couvre INTÉGRALEMENT — le bouton
     * « Valider » du front peut se désactiver trop tôt par bug, jamais ce
     * garde-fou.
     */
    public function enregistrerRelecture(Request $request, string $id, RelectureDirigeeService $service): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);
        Gate::authorize('update', $document);

        $validated = $request->validate([
            'points_vus' => ['required', 'array'],
            'points_vus.*' => ['uuid'],
            'sondage_confirmes' => ['required', 'array'],
            'sondage_confirmes.*' => ['uuid'],
        ]);

        $dernierRun = $document->controleRuns()->orderByDesc('date')->orderByDesc('id')->first();
        if ($dernierRun === null) {
            return $this->error(null, 'Ce document n\'a encore aucun passage du jeu de détecteurs à relire.', 422);
        }

        $pointsObligatoires = $service->pointsObligatoires($document);
        $sondageAttendu = $service->sondage($document, $dernierRun->version_jeu);

        $pointsManquants = $pointsObligatoires->diff($validated['points_vus']);
        $sondageManquant = $sondageAttendu->diff($validated['sondage_confirmes']);

        if ($pointsManquants->isNotEmpty() || $sondageManquant->isNotEmpty()) {
            return $this->error(
                ['points_manquants' => $pointsManquants->values(), 'sondage_manquant' => $sondageManquant->values()],
                'Tous les points d\'observation obligatoires et tout le sondage doivent être confirmés.',
                422
            );
        }

        $preuve = DocumentRelecturePreuve::create([
            'document_id' => $document->id,
            'actor_id' => $request->user()?->id,
            'document_controle_run_id' => $dernierRun->id,
            'points_vus' => $validated['points_vus'],
            'sondage_articles' => $sondageAttendu->all(),
            'sondage_confirmes' => $validated['sondage_confirmes'],
        ]);

        return $this->success(['id' => $preuve->id], 'Preuve de relecture enregistrée', 201);
    }
}
