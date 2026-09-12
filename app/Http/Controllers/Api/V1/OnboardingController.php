<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OnboardingJourneyRequest;
use App\Http\Requests\Api\V1\OnboardingStepMutationRequest;
use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use App\Services\Onboarding\OnboardingAnswerValidator;
use App\Services\Onboarding\OnboardingCapabilityFilter;
use App\Services\Onboarding\OnboardingConditionEvaluator;
use App\Services\Onboarding\OnboardingStepWriter;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @group Onboarding
 *
 * Moteur d'onboarding versionné (mibeko-dashboard#136) : parcours communs
 * au web et au mobile, progression par étape à identifiant stable, reprise
 * sur un autre appareil, rejeu sans perte. Les étapes liées au profil
 * (`binding`) délèguent l'écriture à `ProfileAttributeWriter` (#135) via
 * `OnboardingStepWriter` — jamais de duplication de cette logique ici.
 */
class OnboardingController extends Controller
{
    use HttpResponses;

    /** Seule famille de parcours utilisée par #136 ; `key` autorise d'en ajouter d'autres sans nouvelle table. */
    private const JOURNEY_KEY = 'onboarding';

    public function __construct(
        private readonly OnboardingStepWriter $stepWriter,
        private readonly OnboardingAnswerValidator $answerValidator,
        private readonly OnboardingCapabilityFilter $capabilityFilter,
        private readonly OnboardingConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Récupère le parcours applicable et la progression du compte courant.
     * Sert aussi de point de reprise : stateless, tout l'état vit en base.
     * Aucune version active pour la clé demandée → `available:false`,
     * jamais 404/500 — l'accès au produit reste possible sans onboarding.
     *
     * @response 200 {"success":true,"message":"Parcours récupéré avec succès.","data":{"available":true,"journey":{"key":"onboarding","version":1,"steps":[{"key":"usage_context","type":"single_choice","scope":"common","binding":"profile.usage_context","config":{"options":[{"code":"personal","label_key":"onboarding.usage_context.personal"}]},"conditions":[],"supported":true,"progress":{"viewed_at":null,"skipped_at":null,"completed_at":null,"value":null}}]},"enrollment":{"status":"not_started","started_at":null,"completed_at":null,"postponed_at":null,"replay_count":0}}}
     */
    public function index(OnboardingJourneyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $platform = $validated['platform'];
        $knownStepTypes = $validated['known_step_types'] ?? OnboardingJourney::STEP_TYPES;

        return DB::transaction(function () use ($request, $platform, $knownStepTypes) {
            $enrollment = $this->lockedEnrollment($request);

            if ($enrollment === null) {
                return $this->success(
                    ['available' => false, 'journey' => null, 'enrollment' => null],
                    'Aucun parcours actif.'
                );
            }

            return $this->success([
                'available' => true,
                'journey' => $this->journeyPayload($enrollment, $platform, $knownStepTypes),
                'enrollment' => $this->enrollmentPayload($enrollment),
            ], 'Parcours récupéré avec succès.');
        });
    }

    /**
     * Enregistre une vue, une réponse ou un passage sur une étape. Idempotent
     * (`client_mutation_id`) et LWW (`client_updated_at`) — voir
     * `OnboardingStepWriter`. Une inscription déjà `completed` n'écrit plus
     * rien (terminal), sans jamais renvoyer d'erreur.
     *
     * @response 200 {"success":true,"message":"Étape mise à jour avec succès.","data":{"step":{"key":"usage_context","viewed_at":"2026-09-12T10:00:00+00:00","skipped_at":null,"completed_at":"2026-09-12T10:00:05+00:00","value":"professional"},"enrollment":{"status":"in_progress","started_at":"2026-09-12T10:00:00+00:00","completed_at":null,"postponed_at":null,"replay_count":0}}}
     * @response 422 {"success":false,"message":"Étape introuvable","errors":{"step_key":["Étape inconnue de la version active."]}}
     */
    public function updateStep(string $stepKey, OnboardingStepMutationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($stepKey, $validated, $request) {
            $enrollment = $this->lockedEnrollment($request);

            if ($enrollment === null) {
                return $this->error([], 'Aucun parcours actif.', 404);
            }

            if ($enrollment->status === OnboardingEnrollment::STATUS_COMPLETED) {
                $progress = OnboardingStepProgress::where('enrollment_id', $enrollment->id)
                    ->where('step_key', $stepKey)
                    ->first();

                return $this->success([
                    'step' => $progress ? $this->stepProgressPayload($stepKey, $progress) : null,
                    'enrollment' => $this->enrollmentPayload($enrollment),
                ], 'Parcours déjà terminé.');
            }

            $stepDefinition = $enrollment->journey->step($stepKey);

            if ($stepDefinition === null) {
                return $this->error(
                    ['step_key' => ['Étape inconnue de la version active.']],
                    'Étape introuvable',
                    422
                );
            }

            $this->answerValidator->validate($stepDefinition, $validated);

            if (in_array($enrollment->status, [OnboardingEnrollment::STATUS_NOT_STARTED, OnboardingEnrollment::STATUS_POSTPONED], true)) {
                $enrollment->status = OnboardingEnrollment::STATUS_IN_PROGRESS;
                $enrollment->started_at ??= now();
                $enrollment->last_started_at = now();
            }

            $progress = OnboardingStepProgress::where('enrollment_id', $enrollment->id)
                ->where('step_key', $stepKey)
                ->lockForUpdate()
                ->firstOrFail();

            $this->stepWriter->apply($progress, $stepDefinition, $validated);

            $enrollment->last_activity_at = now();
            $enrollment->save();

            $this->maybeCompleteJourney($enrollment, $validated['platform']);

            return $this->success([
                'step' => $this->stepProgressPayload($stepKey, $progress->fresh()),
                'enrollment' => $this->enrollmentPayload($enrollment->fresh()),
            ], 'Étape mise à jour avec succès.');
        });
    }

    /**
     * Reporte le parcours entier (pas une étape) — idempotent sur
     * `client_mutation_id`. Atteignable depuis `not_started` (dismiss avant
     * toute interaction) ou `in_progress`.
     *
     * @response 200 {"success":true,"message":"Parcours reporté.","data":{"enrollment":{"status":"postponed","started_at":null,"completed_at":null,"postponed_at":"2026-09-12T10:05:00+00:00","replay_count":0}}}
     */
    public function postpone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_mutation_id' => ['required', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $enrollment = $this->lockedEnrollment($request);

            if ($enrollment === null) {
                return $this->error([], 'Aucun parcours actif.', 404);
            }

            if ($enrollment->last_client_mutation_id !== $validated['client_mutation_id']
                && in_array($enrollment->status, [OnboardingEnrollment::STATUS_NOT_STARTED, OnboardingEnrollment::STATUS_IN_PROGRESS], true)) {
                $enrollment->status = OnboardingEnrollment::STATUS_POSTPONED;
                $enrollment->postponed_at = now();
                $enrollment->last_client_mutation_id = $validated['client_mutation_id'];
                $enrollment->save();
            }

            return $this->success(['enrollment' => $this->enrollmentPayload($enrollment)], 'Parcours reporté.');
        });
    }

    /**
     * Rejoue un guide déjà terminé — remet `status` à `in_progress` sans
     * toucher à aucune ligne `onboarding_step_progress` (préserve les
     * premières réussites) ni recompter une activation (`replay_count`
     * seulement, jamais un nouveau `started_at`).
     *
     * @response 422 {"success":false,"message":"Le rejeu n'a de sens qu'une fois le parcours terminé.","errors":{"status":["Le parcours n'est pas terminé."]}}
     */
    public function replay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_mutation_id' => ['required', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $enrollment = $this->lockedEnrollment($request);

            if ($enrollment === null) {
                return $this->error([], 'Aucun parcours actif.', 404);
            }

            if ($enrollment->last_client_mutation_id === $validated['client_mutation_id']) {
                return $this->success(['enrollment' => $this->enrollmentPayload($enrollment)], 'Parcours rejoué.');
            }

            if ($enrollment->status !== OnboardingEnrollment::STATUS_COMPLETED) {
                return $this->error(
                    ['status' => ["Le parcours n'est pas terminé."]],
                    "Le rejeu n'a de sens qu'une fois le parcours terminé.",
                    422
                );
            }

            $enrollment->status = OnboardingEnrollment::STATUS_IN_PROGRESS;
            $enrollment->replay_count++;
            $enrollment->last_started_at = now();
            $enrollment->last_client_mutation_id = $validated['client_mutation_id'];
            $enrollment->save();

            return $this->success(['enrollment' => $this->enrollmentPayload($enrollment)], 'Parcours rejoué.');
        });
    }

    /**
     * Charge (ou crée) l'inscription du compte courant à `self::JOURNEY_KEY`,
     * verrouillée (`lockForUpdate()`) pour toute la durée de la transaction
     * appelante — même idiome que `ReviewQueueController::claim()`. Matérialise
     * au passage les lignes de progression manquantes de la définition
     * pinnée (plus jamais de course à l'INSERT ensuite). `null` si aucune
     * version active n'existe : jamais d'exception, l'appelant décide de la
     * réponse "indisponible".
     */
    private function lockedEnrollment(Request $request): ?OnboardingEnrollment
    {
        $journey = OnboardingJourney::query()
            ->where('key', self::JOURNEY_KEY)
            ->where('is_active', true)
            ->first();

        if ($journey === null) {
            return null;
        }

        OnboardingEnrollment::query()->insertOrIgnore([[
            'id' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'journey_id' => $journey->id,
            'journey_key' => self::JOURNEY_KEY,
            'status' => OnboardingEnrollment::STATUS_NOT_STARTED,
            'replay_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $enrollment = OnboardingEnrollment::query()
            ->where('user_id', $request->user()->id)
            ->where('journey_key', self::JOURNEY_KEY)
            ->lockForUpdate()
            ->firstOrFail();

        $existingKeys = OnboardingStepProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->pluck('step_key');

        $rows = collect($enrollment->journey->definition)
            ->reject(fn (array $step) => $existingKeys->contains($step['key']))
            ->map(fn (array $step) => [
                'id' => (string) Str::uuid(),
                'enrollment_id' => $enrollment->id,
                'step_key' => $step['key'],
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            OnboardingStepProgress::query()->insert($rows);
        }

        return $enrollment;
    }

    /**
     * Bascule l'inscription en `completed` dès que toutes les étapes
     * applicables (filtrées par `scope`, PAS par capacité client — une étape
     * non supportée doit quand même compter comme résolue) ont été vues,
     * passées ou répondues.
     */
    private function maybeCompleteJourney(OnboardingEnrollment $enrollment, string $platform): void
    {
        if ($enrollment->status !== OnboardingEnrollment::STATUS_IN_PROGRESS) {
            return;
        }

        $applicableKeys = $this->capabilityFilter->applicableStepKeys($enrollment->journey->definition, $platform);

        $resolvedCount = OnboardingStepProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('step_key', $applicableKeys)
            ->where(fn ($q) => $q->whereNotNull('completed_at')->orWhereNotNull('skipped_at'))
            ->count();

        if ($resolvedCount === count($applicableKeys)) {
            $enrollment->status = OnboardingEnrollment::STATUS_COMPLETED;
            $enrollment->completed_at ??= now();
            $enrollment->save();
        }
    }

    /**
     * @param  list<string>  $knownStepTypes
     * @return array<string, mixed>
     */
    private function journeyPayload(OnboardingEnrollment $enrollment, string $platform, array $knownStepTypes): array
    {
        $journey = $enrollment->journey;
        $progressByKey = $enrollment->stepsProgress()->get()->keyBy('step_key');
        $resolvedValues = $progressByKey->map(fn (OnboardingStepProgress $p) => $p->value)->all();

        $steps = collect($this->capabilityFilter->annotate($journey->definition, $platform, $knownStepTypes))
            ->filter(fn (array $step) => $this->conditionEvaluator->passes($step['conditions'] ?? [], $resolvedValues))
            ->map(function (array $step) use ($progressByKey) {
                $progress = $progressByKey->get($step['key']);

                return [
                    ...$step,
                    'progress' => [
                        'viewed_at' => $progress?->viewed_at?->toIso8601String(),
                        'skipped_at' => $progress?->skipped_at?->toIso8601String(),
                        'completed_at' => $progress?->completed_at?->toIso8601String(),
                        'value' => $progress?->value,
                    ],
                ];
            })
            ->values()
            ->all();

        return ['key' => $journey->key, 'version' => $journey->version, 'steps' => $steps];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepProgressPayload(string $stepKey, OnboardingStepProgress $progress): array
    {
        return [
            'key' => $stepKey,
            'viewed_at' => $progress->viewed_at?->toIso8601String(),
            'skipped_at' => $progress->skipped_at?->toIso8601String(),
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'value' => $progress->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentPayload(OnboardingEnrollment $enrollment): array
    {
        return [
            'status' => $enrollment->status,
            'started_at' => $enrollment->started_at?->toIso8601String(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'postponed_at' => $enrollment->postponed_at?->toIso8601String(),
            'replay_count' => $enrollment->replay_count,
        ];
    }
}
