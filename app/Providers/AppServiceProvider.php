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
    }
}
