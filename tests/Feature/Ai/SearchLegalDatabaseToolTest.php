<?php

use App\Ai\Tools\SearchLegalDatabase;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

/**
 * Invoque la recherche (protégée) de l'outil IA via réflexion.
 *
 * @return array<int, array<string, mixed>>
 */
function invokeToolSearch(SearchLegalDatabase $tool, string $query, ?array $documentIds = null): array
{
    $method = new ReflectionMethod($tool, 'searchArticles');

    return $method->invoke($tool, $query, 5, null, null, $documentIds);
}

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    DocumentType::create(['code' => 'DEC', 'nom' => 'Décret']);

    $this->docTravail = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);
    $this->docSante = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret sur la santé',
    ]);

    $articleTravail = Article::factory()->create([
        'document_id' => $this->docTravail->id,
        'numero_article' => '123',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleTravail->id,
        'contenu_texte' => 'Dispositions sur le licenciement et le préavis.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $articleSante = Article::factory()->create([
        'document_id' => $this->docSante->id,
        'numero_article' => '456',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleSante->id,
        'contenu_texte' => 'Mesures relatives à la sécurité sanitaire.',
        'validity_period' => '[2020-01-01,)',
    ]);
});

it('resolves an "art. N" reference via the unified parser', function () {
    // L'ancien moteur de l'outil (regex /article\s+(\d+)/) ne reconnaissait pas
    // « art. 123 » ; le moteur unifié (parseArticleQuery) le résout.
    $results = invokeToolSearch(new SearchLegalDatabase, 'art. 123');

    expect($results)->toHaveCount(1)
        ->and($results[0]['number'])->toBe('123')
        ->and($results[0]['document_id'])->toBe($this->docTravail->id);
});

it('scopes the search to pinned documents', function () {
    // « sécurité » n'existe que dans le décret santé : scoper sur la loi travail
    // ne doit rien renvoyer, scoper sur le décret santé doit le retrouver.
    expect(invokeToolSearch(new SearchLegalDatabase, 'sécurité', [$this->docTravail->id]))
        ->toBeEmpty();

    $scoped = invokeToolSearch(new SearchLegalDatabase, 'sécurité', [$this->docSante->id]);

    expect($scoped)->not->toBeEmpty()
        ->and(collect($scoped)->pluck('document_id')->unique()->values()->all())
        ->toBe([$this->docSante->id]);
});

it('returns the slim citation shape expected by the front and the MCP tool', function () {
    $results = invokeToolSearch(new SearchLegalDatabase, 'licenciement');

    expect($results)->not->toBeEmpty()
        ->and($results[0])->toHaveKeys([
            'id', 'number', 'order', 'content', 'document_id',
            'document_title', 'document_type', 'node_title',
            'breadcrumb', 'validation_status', 'score',
        ]);
});

it('numbers and de-duplicates sources across successive tool calls', function () {
    // Le handle() de l'outil ajoute source_number et évite les doublons entre
    // appels successifs d'une même requête (alignement des marqueurs [n]).
    $tool = new SearchLegalDatabase;

    $first = json_decode($tool->handle(new Request(['query' => 'licenciement'])), true);
    $second = json_decode($tool->handle(new Request(['query' => 'licenciement'])), true);

    expect($first)->not->toBeEmpty()
        ->and($first[0]['source_number'])->toBe(1)
        // Même article : déjà vu au premier appel, écarté du second.
        ->and($second)->toBeEmpty();
});
