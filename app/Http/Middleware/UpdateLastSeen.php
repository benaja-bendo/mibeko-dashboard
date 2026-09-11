<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            if (! $user->last_seen_at || $user->last_seen_at->diffInMinutes(now()) >= 2) {
                // mibeko-dashboard#129 : `last_seen_at` est exclu du contenu
                // audité (User::$auditExclude), mais owen-it/auditing crée
                // quand même une ligne « updated » à diff vide à chaque save —
                // ce ping de présence ne doit produire aucune ligne d'audit.
                User::withoutAuditing(function () use ($user) {
                    $user->timestamps = false;
                    $user->forceFill(['last_seen_at' => now()])->save();
                    $user->timestamps = true;
                });
            }
        }

        return $next($request);
    }
}
