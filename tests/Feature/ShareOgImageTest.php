<?php

use App\Models\LegalDocument;

/**
 * Écu des pages de partage (mibeko-dashboard#239) : il s'affiche en 64 px, il
 * est donc servi en images matricielles à cette taille (1x, 2x, 3x) et non plus
 * en décalque SVG de 610 ko.
 */
it('draws the shield from raster files that exist instead of the 610 kB SVG', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $html = $this->get("/document/{$document->id}")->assertOk()->getContent();

    preg_match('/<img[^>]*class="logo"[^>]*>/s', $html, $logo);
    expect($logo[0])->not->toContain('logo.svg');

    preg_match_all('#https?://[^/\s"]+/([^\s",]+)#', $logo[0], $fichiers);

    expect($fichiers[1])->not->toBeEmpty()
        ->each(fn ($fichier) => $fichier->toEndWith('.webp')
            ->and(public_path($fichier->value))->toBeFile());
});
