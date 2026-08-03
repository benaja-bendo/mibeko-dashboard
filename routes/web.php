<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\PdfProxyController;
use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Racine de l'hôte API : page de statut « machine » (pas de vitrine, pas
// d'authentification ici). Les humains vont sur mibeko.fr / app.mibeko.fr.
Route::get('/', function (Request $request) {
    $payload = [
        'service' => config('app.name', 'Mibeko').' API',
        'status' => 'ok',
        'version' => 'v1',
        'documentation' => url('/docs/api'),
        'application' => 'https://app.mibeko.fr',
        'website' => 'https://mibeko.fr',
        'health' => url('/up'),
    ];

    if ($request->expectsJson()) {
        return response()->json($payload);
    }

    return response()->view('status', $payload);
})->name('home');

// Public routes for Deep Linking / Web Share. Ces pages portent les balises
// Open Graph + App Links (aperçu social, ouverture in-app) : on NE redirige PAS
// (cela casserait l'unfurl social et le deep-link mobile). À la place, quand le
// contenu est publié sur le site, on émet un `rel=canonical` vers le lecteur
// public mibeko.fr et on autorise l'indexation (consolidation SEO vers mibeko.fr).
//
// Ces deux routes sont ANONYMES (déclarées hors du groupe `auth` plus bas) : elles
// ne doivent donc servir que du corpus publié. `X-Robots-Tag: noindex` empêche
// l'indexation, pas la lecture — un brouillon exposait titre officiel et 200
// caractères de texte en `og:description` à qui connaissait l'UUID. On répond 404
// (et non 403) pour ne pas transformer la route en oracle d'existence.
//
// Contexte de plateforme pour le bouton "ouvrir dans l'app" de ces deux vues.
// Sans Universal Links / App Links valides sur ce domaine (résidu périmé,
// cf. docs/produit/positionnement-site-app.md), le scheme personnalisé
// mibeko:// ne route vers l'app QUE si elle est déjà installée : sans
// détection ni fallback, le bouton ne fait rien de visible quand elle ne
// l'est pas. On calcule donc ici la plateforme et les URLs de store (source
// unique : config/mobile.php) pour construire un lien Android intent://
// (fallback natif vers le Play Store, géré par Chrome) et amorcer le
// fallback JS iOS vers l'App Store (pas d'équivalent intent:// sur Safari).
// Ce fichier étant rechargé à chaque (ré)initialisation de l'application (donc
// plusieurs fois par process en test), la déclaration doit être idempotente.
if (! function_exists('mobileAppLinkContext')) {
    function mobileAppLinkContext(Request $request): array
    {
        $userAgent = (string) $request->userAgent();

        $platform = match (true) {
            (bool) preg_match('/iPad|iPhone|iPod/', $userAgent) => 'ios',
            str_contains($userAgent, 'Android') => 'android',
            default => 'other',
        };

        $androidStoreUrl = (string) config('mobile.store_urls.android');
        parse_str((string) parse_url($androidStoreUrl, PHP_URL_QUERY), $androidStoreQuery);

        $iosStoreUrl = (string) config('mobile.store_urls.ios');
        preg_match('/id(\d+)/', $iosStoreUrl, $iosIdMatch);

        return [
            'platform' => $platform,
            'androidPackage' => $androidStoreQuery['id'] ?? 'cg.mibeko.app',
            'androidStoreUrl' => $androidStoreUrl,
            'iosAppId' => $iosIdMatch[1] ?? '6768865781',
            'iosStoreUrl' => $iosStoreUrl,
        ];
    }
}

Route::get('/article/{articleId}', function (Request $request, string $articleId) {
    $article = Article::with(['document', 'activeVersion'])->findOrFail($articleId);

    $document = $article->document;

    abort_unless(
        $document && $document->curation_status === LegalDocument::STATUS_PUBLISHED,
        404
    );

    $canonical = null;
    if ($document && $document->curation_status === LegalDocument::STATUS_PUBLISHED && $document->slug) {
        $canonical = rtrim((string) config('app.site_url'), '/')
            .'/textes/'.$document->slug.'/article-'.rawurlencode((string) $article->numero_article);
    }

    $response = response()->view('share.article', [
        'article' => $article,
        'canonical' => $canonical,
        ...mobileAppLinkContext($request),
    ]);

    return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
})->name('share.article');

Route::get('/document/{documentId}', function (Request $request, string $documentId) {
    $document = LegalDocument::with(['type', 'institution'])->findOrFail($documentId);

    abort_unless($document->curation_status === LegalDocument::STATUS_PUBLISHED, 404);

    $canonical = null;
    if ($document->curation_status === LegalDocument::STATUS_PUBLISHED && $document->slug) {
        $canonical = rtrim((string) config('app.site_url'), '/').'/textes/'.$document->slug;
    }

    $response = response()->view('share.document', [
        'document' => $document,
        'canonical' => $canonical,
        ...mobileAppLinkContext($request),
    ]);

    return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
})->name('share.document');

Route::middleware(['auth', 'verified'])->group(function () {

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy/{id}', [PdfProxyController::class, 'show'])->name('pdf.proxy');

    // Media Management
    Route::get('/api/media/files', [MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [MediaController::class, 'attachFile'])->name('curation.attach-media');
});
