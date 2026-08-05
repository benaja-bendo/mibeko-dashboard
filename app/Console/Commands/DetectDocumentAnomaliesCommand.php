<?php

namespace App\Console\Commands;

use App\Jobs\DetectDocumentAnomalies;
use App\Models\LegalDocument;
use App\Services\Curation\StructuralAnomalyDetector;
use Illuminate\Console\Command;

/**
 * Lance la détection d'anomalies d'extraction sur un document précis ou tout le
 * corpus. La couche STRUCTURELLE (déterministe, gratuite) tourne toujours ;
 * l'option --llm ajoute la couche SÉMANTIQUE (coûteuse) via le Job dédié.
 */
class DetectDocumentAnomaliesCommand extends Command
{
    protected $signature = 'documents:detect-anomalies
        {id? : UUID du document (sinon tous les documents porteurs d\'articles ou à l\'extraction déclarée terminée)}
        {--llm : Ajoute la couche sémantique (LLM) en plus de la structurelle}
        {--queue : Avec --llm, met le Job LLM en file plutôt que de l\'exécuter en synchrone}';

    protected $description = 'Détecte les anomalies d\'extraction (structurelle + LLM optionnel) en curation_flags';

    public function handle(StructuralAnomalyDetector $detector): int
    {
        // `orWhere('extraction_status', 'completed')` : sans ce second membre, un
        // document sans le moindre article (les 32 actes JO du 02/08/2026, cf.
        // StructuralAnomalyDetector::emptyExtraction) ne serait JAMAIS visité par
        // le scan en masse — exactement le cas que ce contrôle existe pour attraper.
        $query = LegalDocument::query()->where(
            fn ($q) => $q->whereHas('articles')->orWhere('extraction_status', 'completed')
        );

        if ($id = $this->argument('id')) {
            $query->whereKey($id);
        }

        if ((clone $query)->count() === 0) {
            $this->warn('Aucun document à analyser.');

            return self::SUCCESS;
        }

        $withLlm = (bool) $this->option('llm');
        $flagged = 0;
        $documents = 0;

        $query->orderBy('id')->chunkById(50, function ($chunk) use ($detector, $withLlm, &$flagged, &$documents): void {
            foreach ($chunk as $document) {
                $flagged += count($detector->detect($document));
                $documents++;

                if ($withLlm) {
                    $this->option('queue')
                        ? DetectDocumentAnomalies::dispatch($document->id)
                        : DetectDocumentAnomalies::dispatchSync($document->id);
                }
            }
        });

        $llmNote = $withLlm ? ($this->option('queue') ? ' (+ LLM en file)' : ' (+ LLM synchrone)') : '';
        $this->info("Analyse terminée : {$documents} document(s), {$flagged} anomalie(s) structurelle(s){$llmNote}.");

        return self::SUCCESS;
    }
}
