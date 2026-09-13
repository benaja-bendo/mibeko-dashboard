<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingJourney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingJourneyAdministration
{
    public function __construct(private readonly OnboardingDefinitionValidator $definitionValidator) {}

    /** @param list<array<string, mixed>>|null $definition */
    public function draft(string $key, ?array $definition = null): OnboardingJourney
    {
        return DB::transaction(function () use ($key, $definition) {
            $journeys = OnboardingJourney::query()->where('key', $key)->lockForUpdate()->get();
            $draft = $journeys->firstWhere('status', OnboardingJourney::STATUS_DRAFT);

            if ($draft !== null) {
                return $draft;
            }

            $source = $definition ?? $journeys->firstWhere('is_active', true)?->definition;
            if ($source === null) {
                throw ValidationException::withMessages([
                    'definition' => ['Une définition est obligatoire sans version active à copier.'],
                ]);
            }

            return OnboardingJourney::create([
                'key' => $key,
                'version' => ((int) $journeys->max('version')) + 1,
                'status' => OnboardingJourney::STATUS_DRAFT,
                'is_active' => false,
                'definition' => $this->definitionValidator->validate($source),
            ]);
        });
    }

    /** @param list<array<string, mixed>> $definition */
    public function update(OnboardingJourney $journey, array $definition): OnboardingJourney
    {
        $this->ensureDraft($journey);
        $journey->update(['definition' => $this->definitionValidator->validate($definition)]);

        return $journey->fresh();
    }

    public function publish(OnboardingJourney $journey): OnboardingJourney
    {
        $this->ensureDraft($journey);
        $this->definitionValidator->validate($journey->definition);

        return DB::transaction(function () use ($journey) {
            $lockedDraft = OnboardingJourney::query()->lockForUpdate()->findOrFail($journey->id);
            $this->ensureDraft($lockedDraft);

            $active = OnboardingJourney::query()
                ->where('key', $lockedDraft->key)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            $active?->update(['is_active' => false]);

            $lockedDraft->update([
                'status' => OnboardingJourney::STATUS_PUBLISHED,
                'is_active' => true,
                'published_at' => now(),
            ]);

            return $lockedDraft->fresh();
        });
    }

    public function rollback(OnboardingJourney $source): OnboardingJourney
    {
        if (! in_array($source->status, [OnboardingJourney::STATUS_PUBLISHED, OnboardingJourney::STATUS_ARCHIVED], true)) {
            throw ValidationException::withMessages(['journey' => ['Seule une version déjà publiée peut être restaurée.']]);
        }

        if (OnboardingJourney::query()->where('key', $source->key)->where('status', OnboardingJourney::STATUS_DRAFT)->exists()) {
            throw ValidationException::withMessages([
                'journey' => ['Archivez ou publiez le brouillon existant avant un retour arrière.'],
            ]);
        }

        $this->definitionValidator->validate($source->definition);

        return OnboardingJourney::publish($source->key, $source->definition);
    }

    public function archive(OnboardingJourney $journey): OnboardingJourney
    {
        if ($journey->is_active) {
            throw ValidationException::withMessages([
                'journey' => ['Une version active ne peut pas être archivée. Activez d’abord une autre version.'],
            ]);
        }

        $journey->update(['status' => OnboardingJourney::STATUS_ARCHIVED]);

        return $journey->fresh();
    }

    private function ensureDraft(OnboardingJourney $journey): void
    {
        if ($journey->status !== OnboardingJourney::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'journey' => ['Une version publiée est immuable. Créez un brouillon.'],
            ]);
        }
    }
}
