<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pages publiques de partage (Deep Linking / Web Share) : /article/{id} et /document/{id}.
 *
 * Ces pages portent les balises Open Graph + App Links (aperçu social, ouverture
 * in-app) : on NE redirige PAS (cela casserait l'unfurl social et le deep-link
 * mobile). À la place, quand le contenu est publié sur le site, on émet un
 * `rel=canonical` vers le lecteur public mibeko.fr et on autorise l'indexation
 * (consolidation SEO vers mibeko.fr).
 *
 * Ces routes sont ANONYMES (déclarées hors du groupe `auth` de routes/web.php) :
 * elles ne doivent donc servir que du corpus publié. `X-Robots-Tag: noindex`
 * empêche l'indexation, pas la lecture : un brouillon exposait titre officiel et
 * 200 caractères de texte en `og:description` à qui connaissait l'UUID. On répond
 * 404 (et non 403) pour ne pas transformer la route en oracle d'existence.
 *
 * Ces actions vivent dans un contrôleur et non dans des closures qui appelleraient
 * des fonctions de routes/web.php : le déploiement exécute `route:cache`, après
 * quoi routes/web.php n'est plus chargé et toute fonction globale qu'il déclarait
 * n'existe plus (mibeko-dashboard#241 : ces deux pages répondaient 500 en
 * production). Ne rien déclarer de global dans un fichier de routes.
 */
class ShareController extends Controller
{
    public function article(Request $request, string $articleId): Response
    {
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
            'ogImage' => $this->ogImage($canonical),
            ...$this->mobileAppLinkContext($request),
        ]);

        return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
    }

    public function document(Request $request, string $documentId): Response
    {
        $document = LegalDocument::with(['type', 'institution'])->findOrFail($documentId);

        abort_unless($document->curation_status === LegalDocument::STATUS_PUBLISHED, 404);

        $canonical = null;
        if ($document->curation_status === LegalDocument::STATUS_PUBLISHED && $document->slug) {
            $canonical = rtrim((string) config('app.site_url'), '/').'/textes/'.$document->slug;
        }

        $response = response()->view('share.document', [
            'document' => $document,
            'canonical' => $canonical,
            'ogImage' => $this->ogImage($canonical),
            ...$this->mobileAppLinkContext($request),
        ]);

        return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
    }

    /**
     * Image d'aperçu (og:image) de ces deux vues. WhatsApp, Facebook et LinkedIn
     * n'affichent pas un SVG : on reprend le PNG 1200 x 630 que le site génère pour
     * la page canonique (mibeko-site#27 : /textes/{chemin} vers /og/{chemin}.png, avec
     * le type, le titre et la source du texte), et l'image par défaut du site quand
     * la page n'a pas de canonique (document publié sans slug).
     */
    private function ogImage(?string $canonical): string
    {
        $siteUrl = rtrim((string) config('app.site_url'), '/');
        $textesPrefix = $siteUrl.'/textes/';

        if ($canonical === null || ! str_starts_with($canonical, $textesPrefix)) {
            return $siteUrl.'/og-default.png';
        }

        return $siteUrl.'/og/'.substr($canonical, strlen($textesPrefix)).'.png';
    }

    /**
     * Contexte de plateforme pour le bouton « ouvrir dans l'app » de ces deux vues.
     *
     * Sans Universal Links / App Links valides sur ce domaine (résidu périmé,
     * cf. docs/produit/positionnement-site-app.md), le scheme personnalisé
     * mibeko:// ne route vers l'app QUE si elle est déjà installée : sans
     * détection ni fallback, le bouton ne fait rien de visible quand elle ne
     * l'est pas. On calcule donc ici la plateforme et les URLs de store (source
     * unique : config/mobile.php) pour construire un lien Android intent://
     * (fallback natif vers le Play Store, géré par Chrome) et amorcer le
     * fallback JS iOS vers l'App Store (pas d'équivalent intent:// sur Safari).
     *
     * @return array<string, string>
     */
    private function mobileAppLinkContext(Request $request): array
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
