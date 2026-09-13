<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\PreviewOnboardingJourneyRequest;
use App\Http\Requests\Api\V1\Admin\StoreOnboardingJourneyDraftRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOnboardingJourneyDraftRequest;
use App\Http\Resources\V1\Admin\OnboardingJourneyResource;
use App\Models\OnboardingJourney;
use App\Services\Onboarding\OnboardingCapabilityFilter;
use App\Services\Onboarding\OnboardingConditionEvaluator;
use App\Services\Onboarding\OnboardingDefinitionValidator;
use App\Services\Onboarding\OnboardingJourneyAdministration;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;

class OnboardingJourneyController extends Controller
{
    use HttpResponses;

    public function __construct(
        private readonly OnboardingJourneyAdministration $administration,
        private readonly OnboardingDefinitionValidator $definitionValidator,
        private readonly OnboardingCapabilityFilter $capabilityFilter,
        private readonly OnboardingConditionEvaluator $conditionEvaluator,
    ) {}

    public function index(): JsonResponse
    {
        $journeys = OnboardingJourney::query()
            ->withCount('enrollments')
            ->orderBy('key')
            ->orderByDesc('version')
            ->get();

        return $this->success(OnboardingJourneyResource::collection($journeys), 'Versions du parcours récupérées.');
    }

    public function store(StoreOnboardingJourneyDraftRequest $request): JsonResponse
    {
        $draft = $this->administration->draft(
            $request->validated('key', 'onboarding'),
            $request->validated('definition'),
        )->loadCount('enrollments');

        return $this->success(new OnboardingJourneyResource($draft), 'Brouillon prêt.', 201);
    }

    public function update(UpdateOnboardingJourneyDraftRequest $request, OnboardingJourney $onboardingJourney): JsonResponse
    {
        $journey = $this->administration
            ->update($onboardingJourney, $request->validated('definition'))
            ->loadCount('enrollments');

        return $this->success(new OnboardingJourneyResource($journey), 'Brouillon enregistré.');
    }

    public function validateDefinition(PreviewOnboardingJourneyRequest $request): JsonResponse
    {
        $definition = $this->definitionValidator->validate($request->validated('definition'));

        return $this->success([
            'valid' => true,
            'steps_count' => count($definition),
            'platforms' => OnboardingJourney::PLATFORMS,
        ], 'Définition valide.');
    }

    public function preview(PreviewOnboardingJourneyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $definition = $this->definitionValidator->validate($validated['definition']);
        $knownTypes = $validated['known_step_types'] ?? OnboardingJourney::STEP_TYPES;
        $answers = $validated['answers'] ?? [];

        $steps = collect($this->capabilityFilter->annotate($definition, $validated['platform'], $knownTypes))
            ->filter(fn (array $step) => $this->conditionEvaluator->passes($step['conditions'], $answers))
            ->values()
            ->all();

        return $this->success([
            'platform' => $validated['platform'],
            'steps' => $steps,
            'writes_user_data' => false,
            'uses_ai' => false,
        ], 'Prévisualisation générée sans écriture.');
    }

    public function publish(OnboardingJourney $onboardingJourney): JsonResponse
    {
        $published = $this->administration->publish($onboardingJourney)->loadCount('enrollments');

        return $this->success(new OnboardingJourneyResource($published), 'Nouvelle version publiée.');
    }

    public function rollback(OnboardingJourney $onboardingJourney): JsonResponse
    {
        $published = $this->administration->rollback($onboardingJourney)->loadCount('enrollments');

        return $this->success(new OnboardingJourneyResource($published), 'Version restaurée dans une nouvelle publication.');
    }

    public function archive(OnboardingJourney $onboardingJourney): JsonResponse
    {
        $archived = $this->administration->archive($onboardingJourney)->loadCount('enrollments');

        return $this->success(new OnboardingJourneyResource($archived), 'Version archivée.');
    }
}
