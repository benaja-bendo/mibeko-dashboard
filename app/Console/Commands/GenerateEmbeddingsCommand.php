<?php

namespace App\Console\Commands;

use App\Contracts\AiServiceInterface;
use App\Models\ArticleVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingsCommand extends Command
{
    protected AiServiceInterface $aiService;

    public function __construct(AiServiceInterface $aiService)
    {
        parent::__construct();
        $this->aiService = $aiService;
    }

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
            $inputs = $chunk->pluck('contenu_texte')->toArray();

            try {
                $embeddings = $this->aiService->generateEmbeddings($inputs);

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
                $this->newLine();
                $this->error('❌ Erreur AI : '.$e->getMessage());
                Log::error('Erreur Batch Embedding: '.$e->getMessage());
                $hadErrors = true;

                if (str_contains(strtolower($e->getMessage()), 'unauthorized')) {
                    $this->warn('Unauthorized: vérifiez MISTRAL_API_KEY dans le .env du conteneur et exécutez php artisan optimize:clear.');
                    break;
                }

                if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    $this->warn('Rate limit atteint. Essayez avec un batch plus petit ou attendez un peu.');
                    break;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info($hadErrors ? '⚠️ Traitement terminé avec erreurs.' : '✅ Traitement terminé.');

        return $hadErrors ? 1 : 0;
    }
}
