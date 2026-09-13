<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequiredEmailIsVerified
{
    /**
     * Bloque uniquement les comptes créés avec l'obligation de vérification.
     * Les comptes historiques restent utilisables afin de ne pas interrompre
     * brutalement les utilisateurs inscrits avant cette règle.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof MustVerifyEmail
            && $user->email_verification_required
            && ! $user->hasVerifiedEmail()
        ) {
            abort(403, 'Votre adresse e-mail doit être vérifiée.');
        }

        return $next($request);
    }
}
