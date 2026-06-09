<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * @group Two-Factor Authentication
 *
 * Expose le 2FA TOTP de Fortify aux clients en authentification par jeton (SPA).
 * La confirmation par mot de passe de Fortify étant basée sur la session web, on
 * exige ici le mot de passe courant dans le corps des requêtes sensibles.
 */
class TwoFactorController extends Controller
{
    use HttpResponses;

    /**
     * Retourne l'état du 2FA pour l'écran Sécurité.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'recovery_codes_count' => $user->hasEnabledTwoFactorAuthentication()
                ? count($user->recoveryCodes())
                : 0,
        ]);
    }

    /**
     * Démarre l'activation : génère secret + codes de récupération (état non confirmé).
     *
     * Retourne le QR code (SVG) et l'URL otpauth pour la saisie manuelle. Le 2FA
     * n'est réellement actif qu'après confirmation d'un code TOTP valide.
     */
    public function store(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();
        $enable($user);
        $user->refresh();

        return $this->success([
            'svg' => $user->twoFactorQrCodeSvg(),
            'otpauth_url' => $user->twoFactorQrCodeUrl(),
            'recovery_codes' => $user->recoveryCodes(),
        ], 'Activation du 2FA initiée : confirmez avec un code.');
    }

    /**
     * Confirme l'activation avec un code TOTP issu de l'application d'authentification.
     */
    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $confirm($request->user(), $validated['code']);

        return $this->success(null, 'Double authentification activée.');
    }

    /**
     * Régénère les codes de récupération (invalide les anciens).
     */
    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();
        $generate($user);
        $user->refresh();

        return $this->success(['recovery_codes' => $user->recoveryCodes()], 'Codes de récupération régénérés.');
    }

    /**
     * Désactive complètement le 2FA (efface secret + codes).
     */
    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $this->confirmPassword($request);

        $disable($request->user());

        return $this->success(null, 'Double authentification désactivée.');
    }

    /**
     * Vérifie le mot de passe courant fourni dans le corps de la requête.
     *
     * Remplace la confirmation par session de Fortify, inadaptée à un SPA en
     * authentification par jeton.
     */
    private function confirmPassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);
    }
}
