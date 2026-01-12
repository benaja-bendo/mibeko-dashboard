<?php

namespace App\Console\Commands;

use App\Models\ArticleVersion;
use App\Contracts\AiServiceInterface;
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
    protected $signature = 'mibeko:generate-embeddings {--limit=100 : Nombre d\'articles à traiter} {--batch=20 : Taille du batch pour OpenAI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère les embeddings manquants pour les articles en utilisant le batching.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $batchSize = $this->option('batch');

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

                // Petit délai pour le rate limit s'il y a beaucoup de batches
                if ($chunks->count() > 1) {
                    usleep(500000);
                }

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Erreur AI : " . $e->getMessage());
                Log::error("Erreur Batch Embedding: " . $e->getMessage());

                if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    $this->warn("Rate limit atteint. Essayez avec un batch plus petit ou attendez un peu.");
                    break;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ Traitement terminé.');

        return 0;
    }
}
