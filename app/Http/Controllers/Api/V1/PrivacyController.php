<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Privacy & GDPR
 *
 * Droit d'accès (export des données personnelles) et droit à l'effacement
 * (suppression de compte). Les opérations sont tracées via l'audit applicatif.
 */
class PrivacyController extends Controller
{
    use HttpResponses;

    /**
     * Exporte les données personnelles de l'utilisateur au format JSON (RGPD art. 20).
     *
     * Regroupe identité, profil étendu, préférences, consentements et notifications
     * en un fichier téléchargeable. Aucune donnée d'un autre utilisateur n'est incluse.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user()->load(
            'mobileProfile', 'settings', 'notifications', 'roles', 'tags',
            'onboardingEnrollments.stepsProgress', 'onboardingEnrollments.journey',
            'productActivationEvents'
        );

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->getRoleNames()->values(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            // mibeko-dashboard#135 : cadre d'usage/métier/intérêts inclus au
            // même titre que le reste du profil étendu.
            'profile' => array_merge(
                $user->mobileProfile?->only(['phone', 'profession', 'usage_context', 'job_title', 'company', 'dob', 'gender']) ?? [],
                ['interests' => $user->tags->pluck('slug')->all()]
            ),
            'settings' => $user->settings?->only([
                'locale', 'timezone', 'date_format', 'notification_preferences',
                'marketing_consent', 'marketing_consent_at', 'analytics_consent', 'analytics_consent_at',
            ]),
            'notifications' => $user->notifications->map->only(['title', 'message', 'type', 'read_at', 'created_at']),
            // mibeko-dashboard#136 : progression d'onboarding. `value` est
            // déjà NULL en base pour un binding sensible (OnboardingStepWriter)
            // — rien à filtrer ici en plus, l'export ne fait que refléter l'état stocké.
            'onboarding' => $user->onboardingEnrollments->map(fn ($enrollment) => [
                'journey_key' => $enrollment->journey_key,
                'journey_version' => $enrollment->journey?->version,
                'status' => $enrollment->status,
                'started_at' => $enrollment->started_at?->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'replay_count' => $enrollment->replay_count,
                'steps' => $enrollment->stepsProgress->map(fn ($progress) => [
                    'step_key' => $progress->step_key,
                    'viewed_at' => $progress->viewed_at?->toIso8601String(),
                    'skipped_at' => $progress->skipped_at?->toIso8601String(),
                    'completed_at' => $progress->completed_at?->toIso8601String(),
                    'value' => $progress->value,
                ]),
            ]),
            // mibeko-dashboard#137 : détail nominatif d'activation produit.
            // Schéma déjà borné à des identifiants opaques — rien à filtrer,
            // l'export reflète l'état stocké.
            'product_activation' => $user->productActivationEvents->map(fn ($event) => [
                'event_type' => $event->event_type,
                'surface' => $event->surface,
                'reference_type' => $event->reference_type,
                'reference_id' => $event->reference_id,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
        ];

        $filename = 'mibeko-donnees-'.$user->id.'-'.now()->format('Ymd').'.json';

        // StreamedResponse plutôt que success() : c'est un téléchargement, pas une réponse API.
        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Supprime le compte (droit à l'effacement, RGPD art. 17).
     *
     * Exige le mot de passe courant, révoque tous les jetons puis applique un
     * soft-delete (conservation temporaire pour obligations légales avant purge).
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return $this->success(null, 'Votre compte a été supprimé.');
    }
}
