<?php

use App\Models\LegalDocument;

/**
 * Les pages de partage doivent guider l'utilisateur vers l'installation de
 * l'app quand elle n'est pas déjà présente : sans détection de plateforme ni
 * fallback store, le bouton "ouvrir dans l'app" ne faisait rien de visible
 * pour qui n'avait pas l'app (le clic tentait mibeko:// puis restait sur la
 * page, silencieusement). Voir routes/web.php::mobileAppLinkContext().
 */
it('sends an Android visitor to an intent:// link with a Play Store fallback', function () {
    config(['app.site_url' => 'https://mibeko.fr']);
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0'])
        ->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertSee("intent://document/{$document->id}#Intent;scheme=mibeko;package=cg.mibeko.app;", false);
    $response->assertSee('S.browser_fallback_url='.rawurlencode('https://play.google.com/store/apps/details?id=cg.mibeko.app'), false);
    $response->assertSee('al:android:package" content="cg.mibeko.app"', false);
});

it('sends an iOS visitor a scheme link with a JS App Store fallback', function () {
    config(['app.site_url' => 'https://mibeko.fr']);
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'])
        ->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertSee("mibeko://document/{$document->id}", false);
    $response->assertSee('id="app-btn"', false);
    $response->assertSee('data-store-url="https://apps.apple.com/app/id6768865781"', false);
    $response->assertSee('apple-itunes-app" content="app-id=6768865781,', false);
});

it('hides the app button for a desktop visitor and promotes the site link', function () {
    config(['app.site_url' => 'https://mibeko.fr']);
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0'])
        ->get("/document/{$document->id}");

    $response->assertOk();
    $response->assertDontSee('id="app-btn"', false);
    $response->assertDontSee('intent://document', false);
    $response->assertSee('Lire sur mibeko.fr');
});
