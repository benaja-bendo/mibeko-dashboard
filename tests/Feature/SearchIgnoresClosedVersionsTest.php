<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * Une correction de contenu (PATCH /articles/{id}) ferme l'ancienne version au
 * lieu de la supprimer — c'est l'historique, cf. Article::activeVersion. La
 * recherche doit ignorer les versions fermées : sans quoi un texte déjà
 * remplacé reste indéfiniment trouvable à côté du texte à jour (constat du
 * 03/08/2026 sur la Loi constitutionnelle n° 2-2022 et l'Arrêté n° 3034).
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur les baux commerciaux',
        'curation_status' => 'published',
    ]);
    $this->article = Article::factory()->create([
        'document_id' => $this->document->id,
        'numero_article' => '12',
    ]);
});

it('ignore une version fermée par une correction et ne remonte que la version active', function () {
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Ancien texte mentionnant le motcleobsolete avant correction.',
        'validity_period' => '[2020-01-01,2026-08-03)',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Nouveau texte sur les baux commerciaux et leur renouvellement.',
        'validity_period' => '[2026-08-03,)',
    ]);

    $response = $this->getJson('/api/v1/library/search?q=motcleobsolete');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('remonte toujours la version active quand aucune fermeture n\'existe', function () {
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Texte sur les baux commerciaux et leur renouvellement.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/library/search?q=renouvellement');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('number'))->toContain('12');
});
