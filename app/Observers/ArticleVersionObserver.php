<?php

namespace App\Observers;

use App\Models\ArticleVersion;
use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Log;

class ArticleVersionObserver
{
    protected AiServiceInterface $aiService;

    /**
     * Flag to globally disable embedding generation.
     * Useful for seeders or bulk imports.
     */
    public static bool $shouldSkipEmbeddings = false;

    public function __construct(AiServiceInterface $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Handle the ArticleVersion "saved" event.
     * Génère automatiquement l'embedding quand le contenu change.
     */
    public function saved(ArticleVersion $articleVersion): void
    {
        if (static::$shouldSkipEmbeddings) {
            return;
        }

        // On ne génère l'embedding que si le contenu a changé ou si c'est une nouvelle version
        if ($articleVersion->wasChanged('contenu_texte') || $articleVersion->wasRecentlyCreated) {
            $this->generateEmbedding($articleVersion);
        }
    }

    /**
     * Génère l'embedding via le service d'IA et l'enregistre.
     */
    protected function generateEmbedding(ArticleVersion $articleVersion): void
    {
        if (empty($articleVersion->contenu_texte)) {
            return;
        }

        $maxRetries = 3;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            try {
                $embedding = $this->aiService->generateEmbedding($articleVersion->contenu_texte);

                // On utilise saveQuietly pour éviter une boucle infinie avec l'événement 'saved'
                $articleVersion->embedding = $embedding;
                $articleVersion->saveQuietly();

                // Petit délai pour éviter le rate limit en dev
                if (app()->runningInConsole()) {
                    usleep(200000); // 0.2 seconde
                }

                return; // Succès, on sort de la boucle

            } catch (\Exception $e) {
                if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        $delay = $retryCount * 2; // 2s, 4s...
                        if (app()->runningInConsole()) {
                            echo "\033[33m⏳ Rate limit atteint, nouvelle tentative dans {$delay}s...\033[0m\n";
                        }
                        sleep($delay);
                        continue;
                    }
                }

                $errorMessage = 'Erreur lors de la génération de l\'embedding pour l\'article version ' . $articleVersion->id . ': ' . $e->getMessage();
                Log::error($errorMessage);

                // Si on est en ligne de commande (ex: seeders), on l'affiche directement
                if (app()->runningInConsole()) {
                    echo "\033[31m⚠️  [AI Error] " . $e->getMessage() . "\033[0m\n";
                }
                break;
            }
        }
    }
}
