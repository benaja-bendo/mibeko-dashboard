<?php

use App\Models\Article;
use App\Models\DocumentType;
use App\Models\JurisprudenceCitation;
use App\Models\LegalDocument;
use Illuminate\Database\QueryException;

/**
 * `JURIS` est ajouté par `SystemRequirementsSeeder`, jamais par une
 * migration : chaque test qui en a besoin le crée lui-même, comme le reste
 * de la suite le fait pour tout `document_types.code` requis par une FK.
 */
function decisionType(): DocumentType
{
    return DocumentType::firstOrCreate(
        ['code' => 'JURIS'],
        ['nom' => 'Décision de justice', 'niveau_hierarchique' => 130]
    );
}

it('lie une décision à un article cité', function () {
    $decision = LegalDocument::factory()->create(['type_code' => decisionType()->code]);
    $article = Article::factory()->create();

    $citation = JurisprudenceCitation::factory()->create([
        'decision_id' => $decision->id,
        'cited_article_id' => $article->id,
    ]);

    expect($citation->decision->is($decision))->toBeTrue()
        ->and($citation->citedArticle->is($article))->toBeTrue();
});

it('accepte une citation sans correspondance dans le corpus', function () {
    $citation = JurisprudenceCitation::factory()->sansCorrespondance()->create();

    expect($citation->cited_article_id)->toBeNull()
        ->and($citation->reference_brute)->not->toBeEmpty();
});

it('refuse de dupliquer la même citation pour la même décision', function () {
    $decision = LegalDocument::factory()->create(['type_code' => decisionType()->code]);

    JurisprudenceCitation::factory()->create([
        'decision_id' => $decision->id,
        'reference_brute' => "l'article 301 de l'Acte uniforme portant sur le droit commercial général",
    ]);

    expect(fn () => JurisprudenceCitation::factory()->create([
        'decision_id' => $decision->id,
        'reference_brute' => "l'article 301 de l'Acte uniforme portant sur le droit commercial général",
    ]))->toThrow(QueryException::class);
});

it('supprime les citations quand la décision est supprimée', function () {
    $decision = LegalDocument::factory()->create(['type_code' => decisionType()->code]);
    $citation = JurisprudenceCitation::factory()->create(['decision_id' => $decision->id]);

    $decision->forceDelete();

    expect(JurisprudenceCitation::query()->find($citation->id))->toBeNull();
});
