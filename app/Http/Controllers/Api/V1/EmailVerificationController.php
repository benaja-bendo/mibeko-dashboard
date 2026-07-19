<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Vérification e-mail
 *
 * Renvoi du lien de vérification d'adresse e-mail (app mobile / SPA).
 */
class EmailVerificationController extends Controller
{
    /**
     * Renvoie la notification de vérification d'e-mail à l'utilisateur connecté.
     *
     * Notification Laravel standard (`Illuminate\Auth\Notifications\VerifyEmail`).
     * Si l'adresse est déjà vérifiée, on ne renvoie rien mais on répond quand
     * même 202 (idempotent, aucune fuite d'état). L'état de vérification est
     * exposé par le profil (`email_verified`, `email_verified_at`).
     *
     * @response 202
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return $this->success(null, 'Si votre adresse n\'est pas vérifiée, un e-mail vient de vous être envoyé.', 202);
    }
}
