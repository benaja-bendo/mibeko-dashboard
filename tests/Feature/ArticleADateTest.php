<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * dashboard#167 — sélectionner un article par date (`?au=YYYY-MM-DD`), volet
 * API de dashboard#157. Fixture EXPLICITE à deux versions légales
 * (`modifie_par_document_id` renseigné), pas les données de production —
 * exigé par le ticket : celles-ci restent des artefacts de pipeline tant que
 * la remédiation dashboard#166 ne les a pas nettoyées.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

/**
 * Un article amendé une fois : une première version puis un amendement réel
 * (texte modificateur renseigné) qui prend effet plus tard.
 */
function documentAvecArticleAmende(): array
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code de test à date',
        'curation_status' => 'published',
    ]);
    $modificateur = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Loi modificative de test',
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '42',
        'ordre_affichage' => 1,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte original de l\'article 42.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2018-01-01', '2021-06-01'),
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte amendé de l\'article 42.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2021-06-01'),
        'modifie_par_document_id' => $modificateur->id,
    ]);

    return [$document->refresh(), $article, $modificateur];
}

it('sélectionne la version en vigueur à une date antérieure à l\'amendement', function () {
    [$document] = documentAvecArticleAmende();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42&au=2019-05-01")
        ->assertOk()
        ->assertJsonPath('data.current_article.content', 'Texte original de l\'article 42.')
        ->assertJsonPath('data.current_article.au', '2019-05-01')
        ->assertJsonPath('data.current_article.version_found', true);
});

it('sélectionne la version amendée à une date postérieure à l\'amendement', function () {
    [$document] = documentAvecArticleAmende();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42&au=2023-01-01")
        ->assertOk()
        ->assertJsonPath('data.current_article.content', 'Texte amendé de l\'article 42.')
        ->assertJsonPath('data.current_article.version_found', true);
});

it('retombe sur la version en vigueur sans paramètre au=', function () {
    [$document] = documentAvecArticleAmende();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42")
        ->assertOk()
        ->assertJsonPath('data.current_article.content', 'Texte amendé de l\'article 42.')
        ->assertJsonPath('data.current_article.au', null)
        ->assertJsonPath('data.current_article.version_found', true);
});

it('renvoie une réponse explicite pour une date antérieure à la première version, jamais une 404 muette', function () {
    [$document] = documentAvecArticleAmende();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42&au=2010-01-01")
        ->assertOk()
        ->assertJsonPath('data.current_article.content', null)
        ->assertJsonPath('data.current_article.version_found', false)
        ->assertJsonPath('data.current_article.earliest_known_date', '2018-01-01');
});

it('rejette un format de date invalide en 422', function () {
    [$document] = documentAvecArticleAmende();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42&au=hier")
        ->assertStatus(422);
});

it('expose l\'historique des versions avec le texte modificateur', function () {
    [$document, , $modificateur] = documentAvecArticleAmende();

    $reponse = $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=42")
        ->assertOk();

    $versions = $reponse->json('data.current_article.versions');
    expect($versions)->toHaveCount(2)
        ->and($versions[0]['start'])->toBe('2018-01-01')
        ->and($versions[0]['end'])->toBe('2021-06-01')
        ->and($versions[0]['is_current'])->toBeFalse()
        ->and($versions[0]['modifie_par'])->toBeNull()
        ->and($versions[1]['start'])->toBe('2021-06-01')
        ->and($versions[1]['end'])->toBeNull()
        ->and($versions[1]['is_current'])->toBeTrue()
        ->and($versions[1]['modifie_par'])->toBe('Loi modificative de test');
});
