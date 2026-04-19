<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use App\Traits\SearchesArticles;

class SearchLegalDatabase implements Tool
{
    use SearchesArticles;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Recherche dans la base de données juridique Mibeko (lois, constitutions, codes). Utilise des mots-clés pertinents (ex: "conditions mariage").';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'] ?? '';
        $limit = $request['limit'] ?? 5;
        $documentType = $request['document_type'] ?? null;
        $documentTitle = $request['document_title'] ?? null;

        // Retourne les résultats au format JSON pour l'IA
        return json_encode($this->searchArticles($query, $limit, $documentType, $documentTitle));
    }

    /**
     * Get the tool's schema definition.
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
}
