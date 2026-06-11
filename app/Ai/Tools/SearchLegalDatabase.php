<?php

namespace App\Ai\Tools;

use App\Traits\SearchesArticles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchLegalDatabase implements Tool
{
    use SearchesArticles;

    /**
     * Décalage de numérotation : les appels successifs de l'outil dans une même
     * requête continuent la numérotation, pour que chaque 'source_number' reste
     * unique et corresponde à l'ordre d'affichage des sources côté interface.
     */
    protected int $sourceOffset = 0;

    /**
     * Articles déjà retournés lors d'un appel précédent de la même requête :
     * ils sont écartés des appels suivants pour préserver l'alignement entre
     * les marqueurs [n] et la liste de sources affichée (pas de doublon).
     *
     * @var array<string, true>
     */
    protected array $seenArticleIds = [];

    /**
     * @param  array<int, string>  $documentIds  Restreint la recherche à ces documents (références épinglées).
     */
    public function __construct(public array $documentIds = []) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Recherche dans la base de données juridique Mibeko (lois, constitutions, codes). Utilise des mots-clés pertinents (ex: "conditions mariage"). Chaque extrait retourné porte un champ source_number à utiliser pour les marqueurs de citation [n].';
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

        $results = $this->searchArticles(
            $query,
            $limit,
            $documentType,
            $documentTitle,
            $this->documentIds === [] ? null : $this->documentIds,
        );

        $results = array_values(array_filter(
            $results,
            fn (array $result) => ! isset($this->seenArticleIds[$result['id']]),
        ));

        foreach ($results as $index => $result) {
            $results[$index]['source_number'] = $this->sourceOffset + $index + 1;
            $this->seenArticleIds[$result['id']] = true;
        }
        $this->sourceOffset += count($results);

        // Retourne les résultats au format JSON pour l'IA
        return json_encode($results);
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
