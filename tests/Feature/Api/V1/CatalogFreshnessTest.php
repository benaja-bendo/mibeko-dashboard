<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Le catalogue est le seul contrat qui permet à l'app mobile de savoir ce qui
 * a changé sans télécharger le corpus. Ces tests verrouillent les propriétés
 * dont dépend le rafraîchissement différentiel.
 */
function catalogEntryFor(string $documentId): ?array
{
    $resources = test()->getJson('/api/v1/catalog')->json('data.resources');

    return collect($resources)->firstWhere('id', $documentId);
}

it('expose les champs de fraîcheur pour chaque document publié', function () {
    // Seul un texte consolidé (STOCK) porte une date « à jour au » : la
    // contrainte de schéma l'interdit sur un acte unitaire (FLUX).
    $document = LegalDocument::factory()->create([
        'document_role' => 'STOCK',
        'stock_code' => 'CODE-TRAVAIL',
        'consolidation_as_of' => '2020-03-15',
        'slug' => 'code-du-travail',
    ]);
    Article::factory()->create(['document_id' => $document->id]);

    $entry = catalogEntryFor($document->id);

    expect($entry)->not->toBeNull()
        ->and($entry['slug'])->toBe('code-du-travail')
        ->and($entry['consolidation_as_of'])->toBe('2020-03-15')
        ->and($entry['articles_count'])->toBe(1)
        ->and($entry['version_hash'])->toBeString()->not->toBe('');
});

it('expose une empreinte globale et l\'horloge du serveur', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);

    $data = $this->getJson('/api/v1/catalog')->json('data');

    expect($data['catalog_version'])->toBeString()->not->toBe('')
        ->and($data['server_time'])->toBeString();
});

it('change l\'empreinte du document quand le contenu d\'un article est corrigé', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validation_status' => 'validated',
    ]);

    $before = catalogEntryFor($document->id)['version_hash'];
    $this->travel(2)->seconds();

    $version->update(['contenu_texte' => 'Texte corrigé.']);

    expect(catalogEntryFor($document->id)['version_hash'])->not->toBe($before);
});

it('change l\'empreinte quand un article est ajouté au document', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);

    $before = catalogEntryFor($document->id)['version_hash'];

    Article::factory()->create(['document_id' => $document->id]);

    expect(catalogEntryFor($document->id)['version_hash'])->not->toBe($before);
});

it('change l\'empreinte quand un article est retiré du document', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);
    $removable = Article::factory()->create(['document_id' => $document->id]);

    $before = catalogEntryFor($document->id)['version_hash'];

    // Suppression logique : le document reste publié mais son contenu diffère.
    $removable->delete();

    expect(catalogEntryFor($document->id)['version_hash'])->not->toBe($before);
});

it('retire du catalogue un document dépublié', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);

    expect(catalogEntryFor($document->id))->not->toBeNull();

    // L'absence de la liste vaut signal de retrait : la liste est complète,
    // le client supprime localement ce qu'il n'y retrouve plus.
    $document->update(['curation_status' => 'draft']);

    expect(catalogEntryFor($document->id))->toBeNull();
});

it('laisse l\'empreinte globale stable quand rien ne change', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);

    $first = $this->getJson('/api/v1/catalog')->json('data.catalog_version');
    $second = $this->getJson('/api/v1/catalog')->json('data.catalog_version');

    expect($second)->toBe($first);
});
