<?php

use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\DB;

/**
 * Aperçu des liens partagés (mibeko-dashboard#239). WhatsApp, Facebook et
 * LinkedIn n'affichent pas un og:image en SVG : les pages de partage reprennent
 * le PNG 1200 × 630 que le site génère pour leur page canonique, ou l'image par
 * défaut du site quand il n'y en a pas. L'écu de la carte n'est plus le décalque
 * SVG de 610 ko, mais des images matricielles à sa taille d'affichage.
 */
it('previews a document share link with the PNG of its canonical page', function () {
    config(['app.site_url' => 'https://mibeko.fr']);

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertSee("property=\"og:image\" content=\"https://mibeko.fr/og/{$document->slug}.png\"", false);
    $response->assertSee('property="og:image:type" content="image/png"', false);
    $response->assertSee('property="og:image:alt" content="'.e($document->titre_officiel).'"', false);
});

it('previews an article share link with the PNG of its canonical page', function () {
    config(['app.site_url' => 'https://mibeko.fr']);

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '12 bis',
    ]);

    $response = $this->get("/article/{$article->id}");

    $response->assertOk();
    $response->assertSee("property=\"og:image\" content=\"https://mibeko.fr/og/{$document->slug}/article-12%20bis.png\"", false);
});

it('falls back to the site default preview when the page has no canonical', function () {
    config(['app.site_url' => 'https://mibeko.fr']);

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    DB::table('legal_documents')->where('id', $document->id)->update(['slug' => null]);

    $response = $this->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertDontSee('rel="canonical"', false);
    $response->assertSee('property="og:image" content="https://mibeko.fr/og-default.png"', false);
});

it('draws the shield from raster files that exist instead of the 610 kB SVG', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $html = $this->get("/document/{$document->id}")->assertOk()->getContent();

    expect($html)->not->toContain('logo.svg');

    preg_match('/<img[^>]*class="logo"[^>]*>/s', $html, $logo);
    preg_match_all('#https?://[^/\s"]+/([^\s",]+)#', $logo[0], $fichiers);

    expect($fichiers[1])->not->toBeEmpty()
        ->each(fn ($fichier) => $fichier->toEndWith('.webp')
            ->and(public_path($fichier->value))->toBeFile());
});
