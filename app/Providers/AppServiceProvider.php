<?php

namespace App\Providers;

use App\Models\ArticleVersion;
use App\Observers\ArticleVersionObserver;
use Illuminate\Http\Resources\Json\JsonResource;
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

        \Illuminate\Support\Facades\Gate::define('viewApiDocs', function ($user = null) {
            // Autoriser tout le monde (ou mettre une condition spécifique, par ex: return true;)
            return true;
        });
    }
}
