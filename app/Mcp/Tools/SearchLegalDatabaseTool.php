<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use App\Traits\SearchesArticles;

#[Description('Recherche dans la base de données juridique Mibeko (lois, constitutions, codes). Utilise des mots-clés pertinents (ex: "conditions mariage").')]
class SearchLegalDatabaseTool extends Tool
{
    use SearchesArticles;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $query = $request->get('query');
        $limit = $request->get('limit', 5);
        $documentType = $request->get('document_type');
        $documentTitle = $request->get('document_title');

        $results = $this->searchArticles($query, $limit, $documentType, $documentTitle);

        return Response::text(json_encode(['results' => $results]));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Mots-clés optimisés pour la recherche légale (ex: "conditions divorce")')->required(),
            'limit' => $schema->integer()->description('Nombre de résultats maximum (entre 1 et 10)')->default(5),
            'document_type' => $schema->string()->description('Code du type de document (ex: "CODE", "LOI", "CONSTITUTION")')->nullable(),
            'document_title' => $schema->string()->description('Titre du document pour filtrer (ex: "Code pénal", "Code du travail")')->nullable(),
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
            'results' => $schema->array()->items(
                $schema->object([
                    'id' => $schema->string(),
                    'number' => $schema->string(),
                    'content' => $schema->string(),
                    'document_title' => $schema->string(),
                    'breadcrumb' => $schema->string(),
                    'score' => $schema->number(),
                ])
            )->description('Liste des articles de loi pertinents trouvés.'),
        ];
    }
}
