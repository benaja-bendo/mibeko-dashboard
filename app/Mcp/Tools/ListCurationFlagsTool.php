<?php

namespace App\Mcp\Tools;

use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Traits\AuthorizesMcpCuration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Liste les anomalies de curation d\'un document juridique (non résolues d\'abord, bloquantes en tête), avec leur description, la suggestion de correction éventuelle et la cible (article/division, page PDF). Lecture seule. Utiliser get-article-tool pour lire le contenu d\'un article signalé.')]
class ListCurationFlagsTool extends Tool
{
    use AuthorizesMcpCuration;

    /** Plafond dur du nombre d'anomalies renvoyées par appel. */
    private const MAX_LIMIT = 200;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        // Garde AVANT toute lecture : ne pas servir d'oracle d'existence d'UUID.
        if ($denial = $this->denyUnlessCurator($request)) {
            return $denial;
        }

        $documentId = (string) $request->get('document_id');
        if (! Str::isUuid($documentId)) {
            return Response::error('Paramètre document_id invalide : un UUID est attendu.');
        }

        $document = LegalDocument::find($documentId);
        if (! $document) {
            return Response::error("Document [{$documentId}] introuvable.");
        }

        $openOnly = $request->boolean('open_only', true);
        $limit = min(max((int) $request->get('limit', 50), 1), self::MAX_LIMIT);

        $query = CurationFlag::query()
            ->where('document_id', $document->id)
            ->when($openOnly, fn ($q) => $q->where('resolved', false));

        $total = (clone $query)->count();

        // Même ordre que la vue Contrôle : non résolues d'abord, bloquantes en tête.
        $flags = $query
            ->with(['article:id,numero_article', 'node:id,numero,titre'])
            ->orderBy('resolved')
            ->orderByRaw("CASE severity WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::structured([
            'document_id' => $document->id,
            'document_titre' => $document->titre_officiel,
            'curation_status' => $document->curation_status,
            'total' => $total,
            'flags' => $flags->map(fn (CurationFlag $flag): array => [
                'id' => $flag->id,
                'source' => $flag->source,
                'type_probleme' => $flag->type_probleme,
                'severity' => $flag->severity,
                'description' => $flag->description,
                'suggestion' => $flag->suggestion,
                'confidence' => $flag->confidence,
                'resolved' => (bool) $flag->resolved,
                'article_id' => $flag->article_id,
                'article_numero' => $flag->article?->numero_article,
                'node_id' => $flag->node_id,
                'node_label' => $flag->node ? trim(($flag->node->numero ?? '').' '.($flag->node->titre ?? '')) : null,
                'page' => $flag->anchor['page'] ?? null,
                'created_at' => $flag->created_at?->toIso8601String(),
            ])->values()->all(),
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
            'document_id' => $schema->string()->description('UUID du document juridique')->required(),
            'open_only' => $schema->boolean()->description('Ne renvoyer que les anomalies non résolues (défaut : true)')->default(true),
            'limit' => $schema->integer()->description('Nombre maximum d\'anomalies renvoyées (1-200, défaut : 50)')->default(50),
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
            'document_titre' => $schema->string(),
            'curation_status' => $schema->string()->description('Statut de curation du document (draft/review/validated/published).'),
            'total' => $schema->integer()->description('Nombre total d\'anomalies correspondant au filtre (peut dépasser la liste renvoyée).'),
            'flags' => $schema->array()->items(
                $schema->object([
                    'id' => $schema->string(),
                    'source' => $schema->string()->description('Couche d\'origine : structural, llm, heuristic, human, report.'),
                    'type_probleme' => $schema->string(),
                    'severity' => $schema->string()->nullable()->description('blocking (bloque la publication), warning ou info.'),
                    'description' => $schema->string()->nullable(),
                    'suggestion' => $schema->object([])->nullable()->description('Correction proposée (jamais appliquée automatiquement).'),
                    'confidence' => $schema->number()->nullable(),
                    'resolved' => $schema->boolean(),
                    'article_id' => $schema->string()->nullable(),
                    'article_numero' => $schema->string()->nullable(),
                    'node_id' => $schema->string()->nullable(),
                    'node_label' => $schema->string()->nullable(),
                    'page' => $schema->integer()->nullable()->description('Page du PDF source pour vérification humaine.'),
                    'created_at' => $schema->string()->nullable(),
                ])
            )->description('Anomalies triées : non résolues d\'abord, bloquantes en tête.'),
        ];
    }
}
