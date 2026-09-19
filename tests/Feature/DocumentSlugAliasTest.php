<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentSlugAlias;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Embeddings;

/**
 * Filet d'alias de slug (mibeko-dashboard#155) : toute URL publique de texte
 * reste résolvable après un changement de slug, l'API annonce le slug
 * canonique, et un slug n'est jamais à la fois canonique d'un document et
 * alias d'un autre.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

function documentPublieAvecSlug(string $slug, string $statut = 'published'): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Texte '.$slug,
        'slug' => $slug,
        'curation_status' => $statut,
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte.',
        'validity_period' => '[2020-01-01,)',
    ]);

    return $document->refresh();
}

it('annonce le slug canonique égal au slug demandé dans le cas courant', function () {
    documentPublieAvecSlug('code-de-la-famille');

    $this->getJson('/api/v1/legal-documents/slug/code-de-la-famille')
        ->assertStatus(200)
        ->assertJsonPath('data.canonical_slug', 'code-de-la-famille')
        ->assertJsonPath('data.document.slug', 'code-de-la-famille');

    expect(DocumentSlugAlias::count())->toBe(0);
});

it('garde l\'ancien slug résolvable après un changement et renvoie le canonique', function () {
    $document = documentPublieAvecSlug('loi-2024-12-tronquee');

    $document->update(['slug' => 'loi-2024-12-du-3-mai-2024-portant-code-du-travail']);

    expect(DocumentSlugAlias::where('slug', 'loi-2024-12-tronquee')->value('legal_document_id'))
        ->toBe($document->id);

    $this->getJson('/api/v1/legal-documents/slug/loi-2024-12-tronquee')
        ->assertStatus(200)
        ->assertJsonPath('data.canonical_slug', 'loi-2024-12-du-3-mai-2024-portant-code-du-travail')
        ->assertJsonPath('data.document.id', $document->id)
        ->assertJsonPath('data.articles.0.number', '1');

    $this->getJson('/api/v1/legal-documents/slug/loi-2024-12-du-3-mai-2024-portant-code-du-travail')
        ->assertStatus(200)
        ->assertJsonPath('data.canonical_slug', 'loi-2024-12-du-3-mai-2024-portant-code-du-travail');
});

it('résout une chaîne d\'alias A → B → C directement vers C', function () {
    $document = documentPublieAvecSlug('slug-a');

    $document->update(['slug' => 'slug-b']);
    $document->update(['slug' => 'slug-c']);

    expect(DocumentSlugAlias::pluck('slug')->sort()->values()->all())->toBe(['slug-a', 'slug-b']);

    // Deux appels au plus par test : le limiteur `api` est à 2/min en test.
    foreach (['slug-a', 'slug-b'] as $slug) {
        $this->getJson("/api/v1/legal-documents/slug/{$slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.canonical_slug', 'slug-c');
    }
});

it('refuse qu\'un autre document prenne pour slug l\'ancienne URL d\'un texte', function () {
    $premier = documentPublieAvecSlug('ancienne-url');
    $premier->update(['slug' => 'nouvelle-url']);

    $second = documentPublieAvecSlug('un-autre-texte');

    expect(fn () => $second->update(['slug' => 'ancienne-url']))
        ->toThrow(ValidationException::class);

    expect($second->fresh()->slug)->toBe('un-autre-texte');
});

it('suffixe un slug généré qui serait l\'ancienne URL d\'un autre texte', function () {
    $premier = documentPublieAvecSlug('code-forestier');
    $premier->update(['slug' => 'loi-16-2000-code-forestier']);

    expect(LegalDocument::generateUniqueSlug('Code forestier'))->toBe('code-forestier-2');
    // Le document lui-même a le droit de reprendre son ancien slug.
    expect(LegalDocument::generateUniqueSlug('Code forestier', $premier->id))->toBe('code-forestier');
});

it('supprime l\'alias quand le document reprend son ancien slug', function () {
    $document = documentPublieAvecSlug('slug-a');

    $document->update(['slug' => 'slug-b']);
    $document->update(['slug' => 'slug-a']);

    expect(DocumentSlugAlias::pluck('slug')->all())->toBe(['slug-b']);

    $this->getJson('/api/v1/legal-documents/slug/slug-b')
        ->assertStatus(200)
        ->assertJsonPath('data.canonical_slug', 'slug-a');
});

it('refuse un alias qui porte le slug canonique d\'un document', function () {
    $document = documentPublieAvecSlug('canonique');

    expect(fn () => DocumentSlugAlias::create([
        'slug' => 'canonique',
        'legal_document_id' => $document->id,
    ]))->toThrow(ValidationException::class);
});

it('reste un 404 pour un slug qui n\'est ni canonique ni alias', function () {
    documentPublieAvecSlug('un-texte');

    $this->getJson('/api/v1/legal-documents/slug/slug-qui-nexiste-pas')->assertStatus(404);
});

it('reste un 404 pour l\'alias comme pour le slug d\'un texte non publié', function () {
    $brouillon = documentPublieAvecSlug('brouillon-ancien', 'draft');
    $brouillon->update(['slug' => 'brouillon-nouveau']);

    $this->getJson('/api/v1/legal-documents/slug/brouillon-ancien')->assertStatus(404);
    $this->getJson('/api/v1/legal-documents/slug/brouillon-nouveau')->assertStatus(404);
});

it('ne crée pas d\'alias à la création ni à la réparation d\'un slug vide', function () {
    $document = documentPublieAvecSlug('cree-avec-slug');

    // Réparation silencieuse (backfill planifié)…
    LegalDocument::query()->whereKey($document->id)->update(['slug' => null]);
    expect(LegalDocument::backfillMissingSlugs([$document->id]))->toBe(1);

    // … et réparation par le hook `saving` lors d'une écriture Eloquent ordinaire.
    LegalDocument::query()->whereKey($document->id)->update(['slug' => null]);
    $document->fresh()->update(['titre_officiel' => 'Titre retouché']);

    expect($document->fresh()->slug)->not->toBe('');
    expect(DocumentSlugAlias::count())->toBe(0);
});
