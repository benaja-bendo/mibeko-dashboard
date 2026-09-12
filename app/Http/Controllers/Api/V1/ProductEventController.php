<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductEventStoreRequest;
use App\Models\ProductActivationEvent;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * @group Product Events
 *
 * Mesure de l'activation produit (mibeko-dashboard#137) : deux jalons sans
 * autre source de vérité serveur — recherche utile (résultat ouvert) et
 * source ouverte après une réponse Assistant réussie. Aucun texte de
 * requête ni de réponse juridique, aucune coordonnée : uniquement des
 * identifiants opaques, un horodatage serveur, et des dimensions capturées
 * côté serveur (jamais déclarées par le client).
 */
class ProductEventController extends Controller
{
    use HttpResponses;

    /**
     * Enregistre un événement, de façon idempotente sur `client_event_id`.
     * Toujours 201, même sur un doublon (retry réseau) : le client est
     * censé traiter cet appel en tir-et-oublie, jamais bloquant pour l'action
     * qu'il mesure.
     *
     * @response 201 {"success":true,"message":"Événement enregistré.","data":{"id":"0199...","event_type":"search_useful","created_at":"2026-09-12T10:00:00+00:00"}}
     * @response 422 {"success":false,"message":"The given data was invalid.","errors":{"reference_id":["Aucune référence valide (existante, appartenant au compte, et pertinente pour « source_opened_after_answer »)."]}}
     */
    public function store(ProductEventStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        ProductActivationEvent::query()->insertOrIgnore([[
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event_type' => $validated['event_type'],
            'surface' => $validated['surface'],
            // Capturés côté serveur — jamais des clés acceptées du payload
            // client (cf. ProductEventStoreRequest).
            'usage_context' => $user->mobileProfile?->usage_context,
            'onboarding_journey_version' => $user->onboardingEnrollments()
                ->where('journey_key', 'onboarding')
                ->first()
                ?->journey
                ?->version,
            'reference_type' => ProductActivationEvent::referenceTypeFor($validated['event_type']),
            'reference_id' => $validated['reference_id'],
            'duration_ms' => $validated['duration_ms'] ?? null,
            'client_event_id' => $validated['client_event_id'],
            'created_at' => now(),
        ]]);

        $event = ProductActivationEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', $validated['event_type'])
            ->where('client_event_id', $validated['client_event_id'])
            ->firstOrFail();

        return $this->success([
            'id' => $event->id,
            'event_type' => $event->event_type,
            'created_at' => $event->created_at->toIso8601String(),
        ], 'Événement enregistré.', 201);
    }
}
