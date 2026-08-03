<?php

use App\Models\Article;
use App\Models\LegalDocument;

/**
 * Les pages de partage (deep-link / unfurl social) restent servies sur l'hôte
 * API, mais quand le contenu est publié elles pointent vers le lecteur public
 * mibeko.fr via `rel=canonical` et deviennent indexables (consolidation SEO).
 * Ces routes étant anonymes, un contenu non publié n'y est pas servi du tout :
 * 404, et non « 200 en noindex » — noindex empêche l'indexation, pas la lecture.
 */
it('canonicalises a published document share page to the mibeko.fr reader', function () {
    config(['app.site_url' => 'https://mibeko.fr']);

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertSee('rel="canonical"', false);
    $response->assertSee("https://mibeko.fr/textes/{$document->slug}", false);
    $response->assertSee('Lire sur mibeko.fr');
    $response->assertHeader('X-Robots-Tag', 'all');
});

it('hides an unpublished document share page behind a 404', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    $this->get("/document/{$document->id}")->assertNotFound();
});

it('hides an unpublished article share page behind a 404', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '12',
    ]);

    $this->get("/article/{$article->id}")->assertNotFound();
});

it('builds an article canonical with the article- prefix', function () {
    config(['app.site_url' => 'https://mibeko.fr']);

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '230',
    ]);

    $response = $this->get("/article/{$article->id}");

    $response->assertOk();
    $response->assertSee("https://mibeko.fr/textes/{$document->slug}/article-230", false);
    $response->assertHeader('X-Robots-Tag', 'all');
});
