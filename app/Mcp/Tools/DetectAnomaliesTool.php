<?php

namespace App\Mcp\Tools;

use App\Jobs\DetectDocumentAnomalies;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Services\Curation\StructuralAnomalyDetector;
use App\Traits\AuthorizesMcpCuration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Lance la détection d\'anomalies sur un document juridique en staging : règles structurelles déterministes (gratuites, toujours exécutées) et, en option, analyse sémantique IA des feuilles suspectes (coûteuse). Écrit des signalements dans curation_flags, ne modifie JAMAIS le contenu du corpus. Utiliser ensuite list-curation-flags-tool pour le détail des anomalies.')]
class DetectAnomaliesTool extends Tool
{
    use AuthorizesMcpCuration;

    /**
     * Plafond d'analyses IA par utilisateur et par minute : le job LLM
     * synchrone est coûteux (jusqu'à 6 appels modèle par exécution) et cet
     * outil est conçu pour des agents qui bouclent — même logique de maîtrise
     * du coût que le limiteur `ai_assistant` (cf. AppServiceProvider).
     */
    private const AI_MAX_PER_MINUTE = 5;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, StructuralAnomalyDetector $detector): Response|ResponseFactory
    {
        // Garde AVANT toute lecture : ne pas servir d'oracle d'existence d'UUID.
        if ($denial = $this->denyUnlessCurator($request)) {
            return $denial;
        }

        $documentId = (string) $request->get('document_id');
        if (! Str::isUuid($documentId)) {
            return Response::error('Paramètre document_id invalide : un UUID est attendu.');
        }

        $includeAi = $request->boolean('include_ai');
        $rateKey = 'mcp-ai-analysis:'.($request->user()?->id ?? 'local');

        if ($includeAi && RateLimiter::tooManyAttempts($rateKey, self::AI_MAX_PER_MINUTE)) {
            return Response::error(
                'Limite d\'analyses IA atteinte ('.self::AI_MAX_PER_MINUTE.'/min). Réessayez dans '.RateLimiter::availableIn($rateKey).' s, ou relancez sans include_ai.'
            );
        }

        $document = LegalDocument::find($documentId);
        if (! $document) {
            return Response::error("Document [{$documentId}] introuvable.");
        }

        $structuralCreated = count($detector->detect($document));

        $aiFound = null;
        if ($includeAi) {
            RateLimiter::hit($rateKey, 60);

            DetectDocumentAnomalies::dispatchSync($document->id);

            $aiFound = CurationFlag::where('document_id', $document->id)
                ->where('source', CurationFlag::SOURCE_LLM)
                ->where('resolved', false)
                ->count();
        }

        // Une sévérité NULL est traitée comme bloquante par le garde-fou de
        // publication : on la compte pareil ici pour donner le même signal.
        $openBySeverity = CurationFlag::where('document_id', $document->id)
            ->where('resolved', false)
            ->selectRaw("COALESCE(severity, 'blocking') AS effective_severity, COUNT(*) AS total")
            ->groupBy('effective_severity')
            ->pluck('total', 'effective_severity');

        return Response::structured([
            'document_id' => $document->id,
            'structural_created' => $structuralCreated,
            'ai_found' => $aiFound,
            'open_flags' => [
                'blocking' => (int) ($openBySeverity[CurationFlag::SEVERITY_BLOCKING] ?? 0),
                'warning' => (int) ($openBySeverity[CurationFlag::SEVERITY_WARNING] ?? 0),
                'info' => (int) ($openBySeverity[CurationFlag::SEVERITY_INFO] ?? 0),
            ],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('UUID du document juridique à analyser')->required(),
            'include_ai' => $schema->boolean()->description('Lancer aussi la couche sémantique IA (coûteuse, feuilles suspectes seulement). Par défaut : false, détection structurelle seule.')->default(false),
        ];
    }

    /**
     * Define the output schema for this tool's results.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string(),
            'structural_created' => $schema->integer()->description('Signalements structurels (re)créés par cette exécution.'),
            'ai_found' => $schema->integer()->nullable()->description('Signalements IA ouverts après analyse (null si include_ai=false).'),
            'open_flags' => $schema->object([
                'blocking' => $schema->integer(),
                'warning' => $schema->integer(),
                'info' => $schema->integer(),
            ])->description('Anomalies non résolues du document par sévérité ; les bloquantes empêchent la publication.'),
        ];
    }
}
