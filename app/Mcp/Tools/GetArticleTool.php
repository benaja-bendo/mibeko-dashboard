<?php

namespace App\Mcp\Tools;

use App\Models\Article;
use App\Models\CurationFlag;
use App\Traits\AuthorizesMcpCuration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Lit un article du corpus en staging pour la curation : contenu de la version courante, fil d\'Ariane, page du PDF source et anomalies ouvertes le ciblant. Lecture seule, y compris sur les documents non publiés — sert à examiner un texte signalé avant de proposer une correction (jamais de modification directe).')]
class GetArticleTool extends Tool
{
    use AuthorizesMcpCuration;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        // Garde AVANT toute lecture : ne pas servir d'oracle d'existence d'UUID.
        if ($denial = $this->denyUnlessCurator($request)) {
            return $denial;
        }

        $articleId = (string) $request->get('article_id');
        if (! Str::isUuid($articleId)) {
            return Response::error('Paramètre article_id invalide : un UUID est attendu.');
        }

        $article = Article::with(['activeVersion', 'document.type', 'parentNode'])->find($articleId);
        if (! $article || ! $article->document) {
            return Response::error("Article [{$articleId}] introuvable.");
        }

        $version = $article->activeVersion;

        $openFlags = $article->curationFlags()
            ->where('resolved', false)
            ->orderByRaw("CASE severity WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->get();

        return Response::structured([
            'id' => $article->id,
            'numero_article' => $article->numero_article,
            'breadcrumb' => $article->breadcrumb,
            'validation_status' => $article->validation_status,
            'document' => [
                'id' => $article->document->id,
                'titre_officiel' => $article->document->titre_officiel,
                'curation_status' => $article->document->curation_status,
            ],
            'contenu_texte' => $version?->contenu_texte,
            'version' => $version === null ? null : [
                'id' => $version->id,
                'validation_status' => $version->validation_status,
                'is_verified' => (bool) $version->is_verified,
                'page' => $version->source_locator['page'] ?? null,
                'content_format' => $version->source_locator['content_format'] ?? null,
            ],
            'open_flags' => $openFlags->map(fn (CurationFlag $flag): array => [
                'id' => $flag->id,
                'source' => $flag->source,
                'type_probleme' => $flag->type_probleme,
                'severity' => $flag->severity,
                'description' => $flag->description,
                'suggestion' => $flag->suggestion,
                'confidence' => $flag->confidence,
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
            'article_id' => $schema->string()->description('UUID de l\'article à lire (tel que renvoyé par list-curation-flags-tool ou search-legal-database-tool)')->required(),
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
            'id' => $schema->string(),
            'numero_article' => $schema->string(),
            'breadcrumb' => $schema->string()->description('Fil d\'Ariane : type de texte > document > division parente.'),
            'validation_status' => $schema->string()->nullable(),
            'document' => $schema->object([
                'id' => $schema->string(),
                'titre_officiel' => $schema->string(),
                'curation_status' => $schema->string(),
            ]),
            'contenu_texte' => $schema->string()->nullable()->description('Contenu de la version courante (null si aucune version active : texte perdu).'),
            'version' => $schema->object([
                'id' => $schema->string(),
                'validation_status' => $schema->string()->nullable(),
                'is_verified' => $schema->boolean(),
                'page' => $schema->integer()->nullable()->description('Page du PDF source pour vérification humaine.'),
                'content_format' => $schema->string()->nullable()->description('Feuille spéciale éventuelle : preamble, signature, table.'),
            ])->nullable(),
            'open_flags' => $schema->array()->items(
                $schema->object([
                    'id' => $schema->string(),
                    'source' => $schema->string(),
                    'type_probleme' => $schema->string(),
                    'severity' => $schema->string()->nullable(),
                    'description' => $schema->string()->nullable(),
                    'suggestion' => $schema->object([])->nullable(),
                    'confidence' => $schema->number()->nullable(),
                ])
            )->description('Anomalies non résolues ciblant cet article, bloquantes en tête.'),
        ];
    }
}
