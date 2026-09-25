<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredEmailIsVerified
{
    /**
     * Bloque uniquement les comptes créés avec l'obligation de vérification,
     * et seulement quand le blocage est actif (`auth.verification.enforce`,
     * suspendu depuis le 25/09/2026, mibeko-dashboard#206). Les comptes
     * historiques restent utilisables afin de ne pas interrompre brutalement
     * les utilisateurs inscrits avant cette règle.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->isEmailVerificationEnforced()
            && ! $user->hasVerifiedEmail()
        ) {
            abort(403, 'Votre adresse e-mail doit être vérifiée.');
        }

        return $next($request);
    }
}
