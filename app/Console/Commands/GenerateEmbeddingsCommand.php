<?php

namespace App\Console\Commands;

use App\Models\ArticleVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

class GenerateEmbeddingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mibeko:process-rag
                            {--limit=100 : Nombre d\'articles à traiter}
                            {--batch=20 : Taille du batch pour l\'IA}
                            {--delay=500 : Délai en millisecondes entre les batches pour éviter le rate limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère les embeddings (RAG) manquants pour les articles en utilisant le batching et la gestion du rate limit.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $batchSize = $this->option('batch');
        $delay = $this->option('delay') * 1000; // convert to microseconds
        $hadErrors = false;

        $versions = ArticleVersion::whereNull('embedding')
            ->whereNotNull('contenu_texte')
            ->limit($limit)
            ->get();

        if ($versions->isEmpty()) {
            $this->info('✅ Tous les articles ont déjà des embeddings.');

            return 0;
        }

        $this->info("🚀 Traitement de {$versions->count()} articles (Batch size: {$batchSize})...");

        $chunks = $versions->chunk($batchSize);
        $bar = $this->output->createProgressBar($versions->count());
        $bar->start();

        foreach ($chunks as $chunk) {
            // Tronquer le texte pour éviter l'erreur de limite de tokens (ex: Mistral 8192 tokens max)
            // 8192 tokens ~ 24000-28000 caractères. On se limite prudemment à 20000.
            $inputs = $chunk->pluck('contenu_texte')->map(function ($text) {
                return Str::limit($text, 20000, '');
            })->toArray();

            try {
                $response = Embeddings::for($inputs)->generate();
                $embeddings = $response->embeddings;

                foreach ($embeddings as $index => $embedding) {
                    $version = $chunk->values()[$index];
                    $version->embedding = $embedding;
                    $version->saveQuietly();
                }

                $bar->advance($chunk->count());

                // Délai pour le rate limit
                if ($chunks->count() > 1 && $delay > 0) {
                    usleep($delay);
                }

            } catch (\Exception $e) {
                $errorMessage = strtolower($e->getMessage());

                if (str_contains($errorMessage, 'unauthorized')) {
                    $this->newLine();
                    $this->error('❌ Erreur AI : Unauthorized');
                    $this->warn('Vérifiez la clé API (MISTRAL_API_KEY ou autre) dans le .env et exécutez php artisan optimize:clear.');
                    $hadErrors = true;
                    break;
                }

                if (str_contains($errorMessage, 'rate limit') || str_contains($errorMessage, 'too many requests')) {
                    $this->newLine();
                    $this->warn('⚠️ Rate limit atteint. On attend 5 secondes avant de réessayer...');
                    sleep(5);
                    // On pourrait réessayer ici, mais pour l'instant on skip ce batch
                    $hadErrors = true;

                    continue;
                }

                // Fallback: Traitement individuel si le batch échoue (ex: un texte est encore trop long)
                $this->newLine();
                $this->warn('⚠️ Erreur sur le batch, tentative de traitement individuel... ('.$e->getMessage().')');

                foreach ($chunk as $version) {
                    try {
                        $singleInput = Str::limit($version->contenu_texte, 15000, '');
                        $response = Embeddings::for([$singleInput])->generate();
                        $version->embedding = $response->embeddings[0];
                        $version->saveQuietly();
                        $bar->advance();
                        if ($delay > 0) {
                            usleep($delay);
                        }
                    } catch (\Exception $subE) {
                        $this->newLine();
                        $this->error('❌ Erreur sur l\'article '.$version->article_id.' : '.$subE->getMessage());
                        Log::error('Erreur Embedding Individuel (Article '.$version->article_id.'): '.$subE->getMessage());
                        $hadErrors = true;
                    }
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info($hadErrors ? '⚠️ Traitement terminé avec erreurs.' : '✅ Traitement terminé.');

        return $hadErrors ? 1 : 0;
    }
}
