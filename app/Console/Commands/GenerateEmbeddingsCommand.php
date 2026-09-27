<?php

namespace App\Console\Commands;

use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * Hors du périmètre du correctif d'audit dashboard#169, bien qu'elle écrive
 * elle aussi sur `--connection` via `ArticleVersion::on($connexion)` :
 * `$version->saveQuietly()` (ci-dessous) désactive tous les événements
 * Eloquent le temps de l'appel, y compris ceux dont dépend l'observateur
 * `AuditableObserver` d'owen-it/auditing. Aucun audit n'est donc jamais
 * tenté ici, quelle que soit la connexion — silence voulu pour un backfill
 * d'embeddings à fort volume sur une donnée dérivée, pas un défaut à
 * corriger avec `AuditeSurLaConnexionCible`.
 */
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
                            {--delay=500 : Délai en millisecondes entre les batches pour éviter le rate limit}
                            {--connection= : Connexion cible des versions d\'article (défaut : celle de l\'appli ; pgsql_prod_rw pour la production)}
                            {--document= : Ne vectoriser que les versions d\'un document (UUID) ; --limit s\'applique toujours}';

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
        $connexion = (string) ($this->option('connection') ?: config('database.default'));

        if ($connexion === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : cette commande écrit des embeddings, elle exige pgsql_prod_rw (ou la connexion par défaut en développement).');

            return self::FAILURE;
        }

        $documentId = $this->option('document');

        if ($documentId !== null) {
            if (! Str::isUuid($documentId)) {
                $this->error("--document attend l'UUID d'un document : « {$documentId} ».");

                return self::FAILURE;
            }

            // Un document retiré n'a rien à vectoriser : le dire plutôt que de
            // conclure « rien à faire » sur une faute de frappe.
            if (! LegalDocument::on($connexion)->whereKey($documentId)->exists()) {
                $this->error("Document introuvable ou retiré sur {$connexion} : {$documentId}");

                return self::FAILURE;
            }
        }

        $limit = $this->option('limit');
        $batchSize = $this->option('batch');
        $delay = $this->option('delay') * 1000; // convert to microseconds
        $hadErrors = false;

        $versions = ArticleVersion::on($connexion)
            ->whereNull('embedding')
            ->whereNotNull('contenu_texte')
            // La formule finale (« Fait à … » + signataire) est du bruit pour la
            // recherche sémantique : on ne la vectorise pas. Le préambule (visas)
            // garde une valeur juridique et reste vectorisé.
            ->whereRaw("source_locator->>'content_format' IS DISTINCT FROM 'signature'")
            // Seulement les versions d'un article vivant dans un document vivant
            // (dashboard#210). Le scope SoftDeletes de chaque modèle s'applique à
            // chaque niveau du whereHas : `articles.deleted_at IS NULL`, puis
            // `legal_documents.deleted_at IS NULL`. Celui d'ArticleVersion filtre
            // déjà `article_versions.deleted_at`. La suppression douce d'un article
            // ne touche pas ses versions, qui restent sans embedding : sans ce
            // filtre, le cron les vectorise pour rien, sur le débit Mistral que
            // l'assistant partage (constaté le 27/09/2026, pendant le
            // rechargement du Code du travail).
            // `whereHas` plutôt qu'une jointure : un `join('articles')` sans
            // `select('article_versions.*')` écraserait `id` par celui de
            // l'article, et `saveQuietly()` écrirait l'embedding sur une autre ligne.
            ->whereHas('article', function (Builder $article) use ($documentId) {
                $article->whereHas('document');

                if ($documentId !== null) {
                    $article->where('document_id', $documentId);
                }
            })
            // Ordre stable, contenu le plus récent d'abord : avec --limit, on sait
            // quelles versions passent, et un texte qu'on vient de charger n'attend
            // pas que tout l'arriéré soit traité.
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($versions->isEmpty()) {
            $this->info($documentId !== null
                ? "✅ Toutes les versions du document {$documentId} ont déjà des embeddings."
                : '✅ Tous les articles ont déjà des embeddings.');

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
