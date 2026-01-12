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
        $this->app->singleton(\App\Contracts\AiServiceInterface::class, function ($app) {
            $default = config('ai.default', 'openai');
            $class = config("ai.providers.{$default}.class", \App\Services\Ai\OpenAiService::class);
            return new $class();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        ArticleVersion::observe(ArticleVersionObserver::class);
    }
}
