<?php

namespace App\Ai\Tools;

use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Traits\SearchesArticles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
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
     * Statut d'un appel qui n'a rien trouvé dans le corpus.
     *
     * Rendre ce cas EXPLICITE est le cœur de mibeko-dashboard#15 : un
     * `json_encode([])` muet se lisait comme un résultat vide anodin, et le
     * modèle enchaînait sur ses connaissances générales — d'où la contradiction
     * rapportée par l'utilisateur (« je n'ai pas trouvé », puis une réponse).
     */
    public const AUCUN_EXTRAIT = 'aucun_extrait';

    /**
     * Statut d'un appel dont tous les extraits ont déjà été servis dans le même
     * tour : ce n'est PAS un corpus muet, et le dire évite au modèle de conclure
     * à tort à l'absence de texte.
     */
    public const DEJA_FOURNIS = 'deja_fournis';

    /**
     * Statut d'un appel dont le FILTRE ne désigne aucun document publié — un
     * code de type inventé, un titre absent. Le corpus n'est pas muet pour
     * autant, et le confondre avec {@see self::AUCUN_EXTRAIT} fait affirmer au
     * modèle, avec aplomb, une absence de texte qui n'existe pas.
     */
    public const FILTRE_SANS_CORRESPONDANCE = 'filtre_sans_correspondance';

    /**
     * @param  array<int, string>  $documentIds  Restreint la recherche à ces documents (références épinglées).
     */
    public function __construct(public array $documentIds = []) {}

    /**
     * Extraits contenus dans un résultat d'outil, ou aucun.
     *
     * Les charges de statut ({@see self::AUCUN_EXTRAIT}, {@see self::DEJA_FOURNIS})
     * sont des objets JSON, pas des listes d'extraits : les décoder naïvement
     * ferait apparaître une source fantôme dans l'interface et dans l'historique.
     *
     * @return array<int, mixed>
     */
    public static function extractsFrom(?string $payload): array
    {
        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

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

        if ($results === []) {
            if ($this->filtreSansCorrespondance($documentType, $documentTitle)) {
                return $this->statut(
                    self::FILTRE_SANS_CORRESPONDANCE,
                    $query,
                    'Aucun document publié ne correspond au filtre demandé : le corpus n\'est PAS '
                    .'muet pour autant. Relance la MÊME recherche sans filtre, ou avec un code de '
                    .'type existant parmi : '.implode(', ', self::typeCodes()).'. '
                    .'Ne conclus surtout pas à une absence de texte sur la base de cet appel.',
                );
            }

            return $this->statut(
                self::AUCUN_EXTRAIT,
                $query,
                'Le corpus Mibeko ne contient aucun extrait correspondant à cette recherche. '
                .'Tu n\'as donc AUCUNE source sur ce point : ne réponds pas de mémoire, '
                .'applique la règle de non-réponse.',
            );
        }

        $results = array_values(array_filter(
            $results,
            fn (array $result) => ! isset($this->seenArticleIds[$result['id']]),
        ));

        if ($results === []) {
            return $this->statut(
                self::DEJA_FOURNIS,
                $query,
                'Ces extraits t\'ont déjà été fournis plus haut dans ce tour. '
                .'Le corpus n\'est pas muet sur la question : réutilise les extraits déjà reçus '
                .'et leurs marqueurs [n] plutôt que de conclure à une absence de texte.',
            );
        }

        foreach ($results as $index => $result) {
            $results[$index]['source_number'] = $this->sourceOffset + $index + 1;
            $this->seenArticleIds[$result['id']] = true;
        }
        $this->sourceOffset += count($results);

        // Retourne les résultats au format JSON pour l'IA
        return json_encode($results);
    }

    /**
     * Le filtre lui-même désigne-t-il le vide ?
     *
     * Reproduit exactement les clauses de {@see SearchesArticles} (ILIKE sur le
     * code de type et sur le titre) : si aucun document publié n'y répond, le
     * silence vient du filtre, pas du corpus. Cas observé en rejouant le cas
     * signalé le 21/06 : le modèle inventait le code « ACTE_UNIFORME » (le code
     * réel est « AU »), l'outil ne rendait rien, et le modèle annonçait que le
     * corpus ne contenait pas l'Acte uniforme — publié, 35 articles vivants.
     */
    private function filtreSansCorrespondance(?string $documentType, ?string $documentTitle): bool
    {
        if (($documentType ?? '') === '' && ($documentTitle ?? '') === '') {
            return false;
        }

        return ! LegalDocument::query()
            ->where('curation_status', 'published')
            ->when($documentType, fn ($q) => $q->where('type_code', 'ILIKE', '%'.$documentType.'%'))
            ->when($documentTitle, fn ($q) => $q->where('titre_officiel', 'ILIKE', '%'.$documentTitle.'%'))
            ->when($this->documentIds !== [], fn ($q) => $q->whereIn('id', $this->documentIds))
            ->exists();
    }

    /**
     * Codes de type réellement présents en base.
     *
     * La description du schéma citait trois exemples dont un faux
     * (« CONSTITUTION », alors que le code est « CONST ») : le modèle n'avait
     * aucun moyen de connaître les valeurs admises, et les inventait.
     *
     * @return array<int, string>
     */
    public static function typeCodes(): array
    {
        return Cache::remember(
            'ai_document_type_codes',
            now()->addHour(),
            fn () => DocumentType::orderBy('code')->pluck('code')->all(),
        );
    }

    /**
     * Charge utile d'un appel sans extrait exploitable : un objet nommé, jamais
     * une liste vide — le modèle doit pouvoir distinguer les deux.
     */
    private function statut(string $statut, string $query, string $message): string
    {
        return (string) json_encode([
            'status' => $statut,
            'query' => $query,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Mots-clés optimisés pour la recherche légale (ex: "conditions divorce")')->required(),
            'limit' => $schema->integer()->description('Nombre de résultats maximum (entre 1 et 10)')->default(5),
            'document_type' => $schema->string()->description(
                'Code du type de document. Valeurs admises, à reprendre TELLES QUELLES : '
                .implode(', ', self::typeCodes()).'. Tout autre code ne désigne aucun document.'
            )->nullable(),
            'document_title' => $schema->string()->description('Titre du document pour filtrer (ex: "Code pénal", "Code du travail")')->nullable(),
        ];
    }
}
