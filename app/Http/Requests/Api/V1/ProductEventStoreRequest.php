<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownPayloadKeys;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ProductActivationEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enregistre un événement d'activation produit — mibeko-dashboard#137.
 *
 * Liste blanche volontairement réduite à 5 clés CLIENT : `usage_context` et
 * `onboarding_journey_version` (« l'objectif choisi », la version de
 * parcours) ne sont PAS des clés acceptées — capturées côté serveur au
 * moment de l'écriture (`ProductEventController::store()`), jamais fournies
 * par l'appelant. `reference_type` est dérivé de `event_type`
 * (`ProductActivationEvent::referenceTypeFor()`), jamais fourni non plus.
 * Ça distingue « déclaré par le client » (event_type, surface, reference_id,
 * duration_ms) de « vérifié par le serveur » (tout le reste + l'existence
 * et la propriété de `reference_id`, ci-dessous).
 */
class ProductEventStoreRequest extends FormRequest
{
    use RejectsUnknownPayloadKeys;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return list<string>
     */
    protected function allowedKeys(): array
    {
        return ['event_type', 'surface', 'reference_id', 'duration_ms', 'client_event_id'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(ProductActivationEvent::EVENT_TYPES)],
            'surface' => ['required', Rule::in(ProductActivationEvent::SURFACES)],
            'reference_id' => ['required', 'string', 'uuid'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'client_event_id' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->rejectUnknownPayloadKeys($v));
        $validator->after(fn (Validator $v) => $this->validateReference($v));
    }

    /**
     * « Fait vérifiable par le serveur » : l'existence (et, pour une source
     * ouverte après réponse, la propriété + le succès + la citation) de la
     * ligne pointée par `reference_id` — jamais une simple déclaration.
     */
    private function validateReference(Validator $validator): void
    {
        $eventType = $this->input('event_type');
        $referenceId = $this->input('reference_id');

        if (! in_array($eventType, ProductActivationEvent::EVENT_TYPES, true) || ! is_string($referenceId)) {
            // Déjà signalé par les règles ci-dessus (event_type/reference_id
            // manquants ou invalides) — pas la peine de dupliquer l'erreur.
            return;
        }

        $valid = match ($eventType) {
            ProductActivationEvent::TYPE_SEARCH_USEFUL => Article::query()
                ->whereKey($referenceId)
                ->exists(),
            ProductActivationEvent::TYPE_SOURCE_OPENED_AFTER_ANSWER => AiUsageLog::query()
                ->whereKey($referenceId)
                ->where('user_id', $this->user()->id)
                ->where('status', AiUsageLog::STATUS_SUCCESS)
                ->where('has_citation', true)
                ->exists(),
            default => false,
        };

        if (! $valid) {
            $validator->errors()->add(
                'reference_id',
                "Aucune référence valide (existante, appartenant au compte, et pertinente pour « {$eventType} »)."
            );
        }
    }
}
