<?php

namespace App\Jobs;

use App\Models\ArticleVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * Génère les embeddings d'un lot d'articles (versions actives).
 *
 * Pensé pour tourner dans un Bus::batch() : chaque lot est indépendant et
 * vérifie l'annulation, ce qui permet d'arrêter un gros document en cours de
 * route tout en conservant les embeddings déjà calculés.
 */
class EmbedArticleChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * Limite prudente de caractères ~ tokens (8192 tokens ≈ 24-28k caractères).
     */
    private const MAX_CHARS = 20000;

    /**
     * @param  array<int, string>  $versionIds  IDs des article_versions à traiter.
     */
    public function __construct(public array $versionIds) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $versions = ArticleVersion::query()
            ->whereIn('id', $this->versionIds)
            ->whereNull('embedding')
            ->whereNotNull('contenu_texte')
            ->get()
            ->values();

        if ($versions->isEmpty()) {
            return;
        }

        $inputs = $versions->map(fn (ArticleVersion $v) => Str::limit($v->contenu_texte, self::MAX_CHARS, ''))->all();

        try {
            $response = Embeddings::for($inputs)->generate();

            foreach ($response->embeddings as $index => $embedding) {
                $this->storeEmbedding($versions[$index], $embedding);
            }
        } catch (\Throwable $e) {
            if ($this->isRateLimit($e)) {
                // On rend le lot à la file ; il sera rejoué après un délai.
                $this->release(30);

                return;
            }

            // Un seul texte trop long ne doit pas faire échouer tout le lot.
            $this->embedIndividually($versions);
        }
    }

    /**
     * Repli : traite chaque article isolément avec une troncature plus stricte.
     */
    private function embedIndividually(Collection $versions): void
    {
        foreach ($versions as $version) {
            if ($this->batch()?->cancelled()) {
                return;
            }

            try {
                $response = Embeddings::for([Str::limit($version->contenu_texte, 15000, '')])->generate();
                $this->storeEmbedding($version, $response->embeddings[0]);
            } catch (\Throwable $e) {
                Log::warning("EmbedArticleChunk: échec de la version {$version->id} — {$e->getMessage()}");
            }
        }
    }

    /**
     * Enregistre l'embedding sans déclencher l'observer (évite une double génération).
     */
    private function storeEmbedding(ArticleVersion $version, mixed $embedding): void
    {
        $version->embedding = $embedding;
        $version->saveQuietly();
    }

    private function isRateLimit(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate limit') || str_contains($message, 'too many requests');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("EmbedArticleChunk: lot échoué ({$exception->getMessage()})");
    }
}
