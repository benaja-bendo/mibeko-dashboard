<?php

namespace App\Http\Middleware;

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
                // Éviter de déclencher les événements de mise à jour à chaque fois
                $user->timestamps = false;
                $user->forceFill(['last_seen_at' => now()])->save();
                $user->timestamps = true;
            }
        }

        return $next($request);
    }
}
