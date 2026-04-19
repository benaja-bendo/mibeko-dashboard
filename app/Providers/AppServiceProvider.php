<?php

namespace App\Providers;

use App\Models\ArticleVersion;
use App\Observers\ArticleVersionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        ArticleVersion::observe(ArticleVersionObserver::class);

        Gate::define('viewApiDocs', function ($user = null) {
            // Autoriser tout le monde (ou mettre une condition spécifique, par ex: return true;)
            return true;
        });

        RateLimiter::for('api', function (Request $request) {
            $limit = app()->environment('testing') ? 2 : 60;

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter spécifique pour l'IA basé sur les rôles (Spatie) ou statuts
        RateLimiter::for('ai_assistant', function (Request $request) {
            $user = $request->user();
            
            if (!$user) {
                return Limit::perMinute(5)->by($request->ip());
            }

            // Les administrateurs n'ont pas de limite
            if ($user->hasRole('admin')) {
                return Limit::none();
            }

            // Utilisateurs pro/premium (si tu as un rôle premium)
            if ($user->hasRole('premium')) {
                return Limit::perMinute(60)->by($user->id)->response(function () {
                    return response()->json(['message' => 'Limite de requêtes IA atteinte pour votre abonnement Premium.'], 429);
                });
            }

            // Utilisateurs standards
            return Limit::perMinute(20)->by($user->id)->response(function () {
                return response()->json(['message' => 'Limite de requêtes IA atteinte. Passez à un abonnement supérieur pour plus de requêtes.'], 429);
            });
        });
    }
}
