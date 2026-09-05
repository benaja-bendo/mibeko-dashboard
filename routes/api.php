<?php

use App\Http\Controllers\Api\V1\Admin\AiQuotaTierController as AdminAiQuotaTierController;
use App\Http\Controllers\Api\V1\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Api\V1\Admin\CurationFlagController as AdminCurationFlagController;
use App\Http\Controllers\Api\V1\Admin\DocumentTypeController as AdminDocumentTypeController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\V1\Admin\InstitutionController as AdminInstitutionController;
use App\Http\Controllers\Api\V1\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\V1\Admin\PublishedDocumentExtractionRepairController;
use App\Http\Controllers\Api\V1\Admin\TagController as AdminTagController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Admin\UserInvitationController as AdminUserInvitationController;
use App\Http\Controllers\Api\V1\AiAssistantController;
use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\ArticleSearchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CurationFlagController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentCurationController;
use App\Http\Controllers\Api\V1\DocumentRelationController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\DossierAnnexController;
use App\Http\Controllers\Api\V1\DossierController;
use App\Http\Controllers\Api\V1\DossierEcheanceController;
use App\Http\Controllers\Api\V1\DossierExportController;
use App\Http\Controllers\Api\V1\DossierWebController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\EmbeddingController;
use App\Http\Controllers\Api\V1\EntitlementsController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LegalDocumentController;
use App\Http\Controllers\Api\V1\LegalDocumentDownloadController;
use App\Http\Controllers\Api\V1\LegalDocumentExportController;
use App\Http\Controllers\Api\V1\LibraryAiController;
use App\Http\Controllers\Api\V1\LibraryHomeController;
use App\Http\Controllers\Api\V1\LibrarySearchController;
use App\Http\Controllers\Api\V1\NewsletterSubscriptionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficialJournalController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PreferencesController;
use App\Http\Controllers\Api\V1\PrivacyController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SitemapController;
use App\Http\Controllers\Api\V1\StructureNodeController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\PdfProxyController;
use App\Http\Middleware\EnsureExportEntitled;
use App\Http\Middleware\EnsureMobileReleaseSecret;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Auth — la connexion a son limiteur dédié par email + IP (anti brute-force
    // de compte) ; l'inscription est bornée par IP contre la création en masse.
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Réinitialisation de mot de passe par code OTP (mobile)
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:password_reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password_reset');

    // Acceptation d'une invitation d'équipe (création de compte + auto-login)
    Route::post('invitations/accept', [AdminUserInvitationController::class, 'accept']);

    // Enregistrement d'appareil — route publique : l'app mobile pousse son
    // jeton FCM au démarrage, avant toute connexion (un invité reçoit la veille
    // générale). Le contrôleur rattache l'appareil à l'utilisateur dès qu'un
    // jeton Sanctum accompagne la requête. Quota dédié (clé par appareil, cf.
    // limiteur `device_register`) qui REMPLACE le quota générique `api`.
    Route::post('devices/register', [DeviceController::class, 'register'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:device_register');
    Route::post('devices/unregister', [DeviceController::class, 'unregister']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        // mibeko-dashboard#63 : point unique de vérité du droit d'usage
        // (plan, fonctionnalités, quotas, solde) — web et mobile consomment
        // cette charge à l'identique, aucun ne re-déduit la règle localement.
        Route::get('me/entitlements', [EntitlementsController::class, 'show']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Renvoi du lien de vérification d'e-mail (app mobile / SPA) — quota
        // serré pour éviter le spam d'envoi. L'état est exposé par le profil.
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1');

        // Profile — informations personnelles & mot de passe
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);

        // Préférences — affichage, notifications, consentements RGPD
        Route::get('profile/preferences', [PreferencesController::class, 'show']);
        Route::put('profile/preferences', [PreferencesController::class, 'update']);
        Route::put('profile/notification-preferences', [PreferencesController::class, 'updateNotifications']);
        Route::put('profile/consents', [PreferencesController::class, 'updateConsents']);

        // Sécurité — double authentification (2FA TOTP)
        Route::get('profile/two-factor', [TwoFactorController::class, 'show']);
        Route::post('profile/two-factor', [TwoFactorController::class, 'store']);
        Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::delete('profile/two-factor', [TwoFactorController::class, 'destroy']);

        // Sécurité — sessions actives (jetons Sanctum)
        Route::get('profile/sessions', [SessionController::class, 'index']);
        Route::delete('profile/sessions/others', [SessionController::class, 'destroyOthers']);
        Route::delete('profile/sessions/{id}', [SessionController::class, 'destroy']);

        // Conformité RGPD — export & suppression
        Route::get('profile/export', [PrivacyController::class, 'export']);
        Route::delete('profile', [PrivacyController::class, 'destroy']);

        // Facturation (Cashier / Stripe)
        Route::get('billing', [BillingController::class, 'overview']);
        Route::put('billing/info', [BillingController::class, 'updateInfo']);
        Route::post('billing/checkout', [BillingController::class, 'checkout']);
        Route::get('billing/portal', [BillingController::class, 'portal']);
        Route::get('billing/invoices/{invoiceId}/pdf', [BillingController::class, 'downloadInvoice']);

        // Dossiers — synchronisation multi-appareils (mobile) + liste web via ?full=1
        Route::get('dossiers', [DossierController::class, 'index']);
        Route::post('dossiers/sync', [DossierController::class, 'sync']);

        // Dossiers — CRUD « affaire » du tableau de bord web
        Route::post('dossiers', [DossierWebController::class, 'store']);
        Route::get('dossiers/{dossier}', [DossierWebController::class, 'show']);
        Route::patch('dossiers/{dossier}', [DossierWebController::class, 'update']);
        Route::delete('dossiers/{dossier}', [DossierWebController::class, 'destroy']);
        Route::post('dossiers/{dossier}/echeances', [DossierEcheanceController::class, 'store']);
        Route::patch('echeances/{echeance}', [DossierEcheanceController::class, 'update']);
        Route::delete('echeances/{echeance}', [DossierEcheanceController::class, 'destroy']);

        // Dossiers — annexes (références juridiques, pièces, documents générés)
        Route::post('dossiers/{dossier}/references', [DossierAnnexController::class, 'storeReference']);
        Route::delete('dossiers/{dossier}/references/{target}', [DossierAnnexController::class, 'destroyReference']);
        Route::post('dossiers/{dossier}/pieces', [DossierAnnexController::class, 'storePiece']);
        Route::delete('dossiers/{dossier}/pieces/{piece}', [DossierAnnexController::class, 'destroyPiece']);
        Route::post('dossiers/{dossier}/documents', [DossierAnnexController::class, 'storeDocument']);
        Route::delete('dossiers/{dossier}/documents/{document}', [DossierAnnexController::class, 'destroyDocument']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        // Assistant IA (Mibeko IA)
        Route::get('assistant/references', [AiAssistantController::class, 'references']);
        Route::get('assistant/conversations', [AiAssistantController::class, 'index']);
        Route::get('assistant/conversations/{id}', [AiAssistantController::class, 'show']);
        Route::put('assistant/conversations/{id}', [AiAssistantController::class, 'update']);
        Route::delete('assistant/conversations/{id}', [AiAssistantController::class, 'destroy']);
        Route::post('assistant/chat/{id?}', [AiAssistantController::class, 'chat'])->middleware('throttle:ai_assistant');

        // Avis 👍/👎 sur une réponse de l'assistant.
        Route::post('assistant/messages/{message}/feedback', [AiAssistantController::class, 'feedback']);
        Route::delete('assistant/messages/{message}/feedback', [AiAssistantController::class, 'deleteFeedback']);

        // Bibliothèque — IA à la demande (streaming SSE, sans état)
        Route::post('library/explain', [LibraryAiController::class, 'explain'])->middleware('throttle:ai_assistant');
        Route::post('library/synthesis', [LibraryAiController::class, 'synthesis'])->middleware('throttle:ai_assistant');

        // BE5 - PDF Export : jeton signé à courte durée de vie (mibeko-dashboard#86)
        // pour le clic direct <a href> du lecteur Bibliothèque et l'URL brute
        // mobile, qui ne portent aucun jeton Bearer. Vérifie l'entitlement
        // `export` une fois ici ; l'export lui-même (hors auth:sanctum, plus
        // bas) fait confiance à la signature qui en résulte.
        Route::get('legal-documents/{id}/export-token', [LegalDocumentExportController::class, 'mintDocumentToken']);
        Route::get('articles/{id}/export-token', [LegalDocumentExportController::class, 'mintArticleToken']);
    });

    // Bibliothèque — lecture publique : contenu identique pour tous, mis en
    // cache serveur. Partagée entre le web pro et le mobile (la consultation
    // des textes ne requiert pas de compte ; seule l'IA reste authentifiée).
    Route::get('library/home', [LibraryHomeController::class, 'index']);
    Route::get('library/themes', [LibraryHomeController::class, 'themes']);
    Route::get('library/themes/{slug}', [LibraryHomeController::class, 'themeDocuments']);
    Route::get('library/search', [LibrarySearchController::class, 'search'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:search_public');
    Route::get('library/suggest', [LibrarySearchController::class, 'suggest'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:search_suggest');

    // Plan du site vitrine (sitemap.xml) — documents publiés + numéros d'articles.
    Route::get('sitemap', [SitemapController::class, 'index']);

    // Configuration de l'app mobile (force-update) — publique : appelée au
    // démarrage de l'app, avant toute authentification (cf. config/mobile.php).
    Route::get('app-config', [AppConfigController::class, 'show']);

    // Écriture de `latest_version` par la CI de mibeko-app-kmp après une
    // publication Play Store en production — secret partagé, pas de session
    // humaine (cf. EnsureMobileReleaseSecret).
    Route::middleware(EnsureMobileReleaseSecret::class)
        ->post('app-config/latest-version', [AppConfigController::class, 'updateLatestVersion']);

    // Formulaire de contact public (site vitrine) — limité pour éviter le spam.
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:6,1');

    // Inscription newsletter (site vitrine) — publique, idempotente, sans envoi
    // d'e-mail. Quota par IP contre l'inscription en masse.
    Route::post('newsletter-subscriptions', [NewsletterSubscriptionController::class, 'store'])
        ->middleware('throttle:6,1');

    // Resources
    Route::get('home', [HomeController::class, 'index']);

    // Lecture froide du corpus : la synchronisation d'un téléphone enchaîne
    // une requête par document. Quota dédié, clé par appareil (cf. limiteur
    // `corpus_read`) pour ne pas hacher la synchronisation de 429 derrière le
    // CGNAT des opérateurs congolais.
    // `withoutMiddleware` est indispensable : ce groupe est imbriqué dans le
    // `throttle:api` du préfixe v1, dont le plafond de 60/min par IP
    // s'appliquerait EN PLUS et annulerait tout le bénéfice.
    Route::withoutMiddleware('throttle:api')->middleware('throttle:corpus_read')->group(function () {
        Route::get('catalog', [CatalogController::class, 'index']); // BE1
        Route::get('catalog/stats', [CatalogController::class, 'stats']);
        Route::get('sync', [SyncController::class, 'sync']);
        Route::get('legal-documents/{document}/tree', [StructureNodeController::class, 'tree']);
        Route::get('legal-documents/{id}/download', [LegalDocumentDownloadController::class, 'download']);
        // Le lecteur PDF de l'app mobile charge cette URL dans une WKWebView /
        // WebView native (PdfViewer.ios.kt), qui n'envoie NI `Authorization` NI
        // `X-Mibeko-Device` : la requête est anonyme et retombe sur l'IP. Sous
        // `throttle:api` (60/min par IP) elle rendait un 429 dans la visionneuse
        // — constaté en production le 06/08/2026, page d'erreur HTML affichée à
        // la place du PDF. Ici le plafond par IP passe à 300/min, ce qui absorbe
        // le CGNAT des opérateurs congolais (cf. limiteur `corpus_read`).
        Route::get('legal-documents/{id}/pdf', [PdfProxyController::class, 'show']);
    });

    Route::apiResource('institutions', InstitutionController::class)->only(['index']);
    Route::apiResource('document-types', DocumentTypeController::class)->only(['index']);
    // Déclaré avant l'apiResource pour que « years » ne soit pas capturé par show/{id}
    Route::get('official-journals/years', [OfficialJournalController::class, 'years']);
    Route::apiResource('official-journals', OfficialJournalController::class)->only(['index', 'show'])->names('api.official-journals');

    Route::get('legal-documents/search', [LegalDocumentController::class, 'search']);
    // Vue publique par slug (site vitrine SEO) — publié uniquement.
    Route::get('legal-documents/slug/{slug}', [LegalDocumentController::class, 'showBySlug']);
    Route::apiResource('legal-documents', LegalDocumentController::class)->only(['index', 'show']);

    // Bulk update and delete — editor + admin only
    Route::middleware(['auth:sanctum', 'role:editor|admin'])->group(function () {
        Route::patch('legal-documents/bulk', [LegalDocumentController::class, 'bulkUpdate']);
        Route::delete('legal-documents/bulk', [LegalDocumentController::class, 'bulkDestroy']);
        Route::post('legal-documents', [LegalDocumentController::class, 'store']);
        Route::patch('legal-documents/{id}', [LegalDocumentController::class, 'update']);
        Route::post('legal-documents/{id}/suggest-themes', [LegalDocumentController::class, 'suggestThemes'])
            ->middleware('throttle:ai_assistant');

        // Administration des journaux officiels (la lecture publique reste
        // sur l'apiResource official-journals plus haut)
        Route::post('official-journals', [OfficialJournalController::class, 'store']);
        Route::patch('official-journals/{id}', [OfficialJournalController::class, 'update']);
        Route::delete('official-journals/{id}', [OfficialJournalController::class, 'destroy']);
        // Remplacement d'extraction à partir d'une cible mesurée contre le PDF
        // source. La route est éditoriale, mais le contrôleur réserve aux
        // administrateurs tout document ayant déjà été publié.
        Route::get('legal-documents/{document}/extraction-snapshot', [PublishedDocumentExtractionRepairController::class, 'snapshot'])
            ->name('legal-documents.extraction-snapshot');
        Route::post('legal-documents/{document}/replace-extraction', [PublishedDocumentExtractionRepairController::class, 'replace'])
            ->name('legal-documents.replace-extraction');

        Route::post('legal-documents/{document}/embed', [EmbeddingController::class, 'trigger']);
        Route::delete('legal-documents/{document}/embed', [EmbeddingController::class, 'cancel']);
    });

    // Delete documents — editor + admin
    Route::middleware(['auth:sanctum', 'role:editor|admin'])->group(function () {
        Route::get('legal-documents/{id}/deletion-impact', [LegalDocumentController::class, 'deletionImpact']);
        Route::delete('legal-documents/{id}', [LegalDocumentController::class, 'destroy']);
    });

    // Structure & Articles management
    // Article Search (for mobile app) - BE3 Hybrid
    Route::get('search', [ArticleSearchController::class, 'search']);
    Route::get('articles/search', [ArticleSearchController::class, 'search']);
    // Résolution d'un article isolé (lecture d'un résultat de recherche mobile
    // dont le document n'est pas encore téléchargé localement).
    Route::get('articles/{id}/context', [ArticleSearchController::class, 'context']);

    // Write operations — editor + admin only
    Route::middleware(['auth:sanctum', 'role:editor|admin'])->group(function () {
        Route::post('structure-nodes/{id}/move', [StructureNodeController::class, 'move']);
        Route::apiResource('structure-nodes', StructureNodeController::class)->except(['index', 'show']);
        Route::apiResource('articles', ArticleController::class)->except(['index']);
        Route::post('articles/{article}/versions', [ArticleController::class, 'addVersion']);

        Route::post('articles/{article}/relations', [DocumentRelationController::class, 'store']);
        // Relation document-à-document pure (ex. une Constitution qui en
        // abroge une autre) : `{article}` de la route ci-dessus n'a de sens
        // que pour le cas d'usage éditeur « depuis cet article, lier ce texte
        // cité » — store() ne le lit d'ailleurs jamais. Même contrôleur,
        // même validation, sans exiger un article qui n'existe pas ici.
        Route::post('document-relations', [DocumentRelationController::class, 'store']);
        Route::delete('relations/{id}', [DocumentRelationController::class, 'destroy']);

        // Vue Contrôle : anomalies d'un document (validation humaine).
        Route::get('legal-documents/{id}/curation-flags', [DocumentCurationController::class, 'index']);
        Route::post('legal-documents/{id}/detect-anomalies', [DocumentCurationController::class, 'detect']);
        // L'analyse sémantique exécute jusqu'à ~6 appels LLM en synchrone :
        // même plafond de coût IA que les autres endpoints IA (minute + jour).
        Route::post('legal-documents/{id}/analyze-ai', [DocumentCurationController::class, 'analyzeAi'])
            ->middleware('throttle:ai_assistant');
        Route::patch('curation-flags/{flag}', [DocumentCurationController::class, 'update']);
    });

    // Lecture des relations — routes publiques (comme le reste du corpus REST).
    // Le dashboard éditeur les consomme avec un jeton Bearer editor/admin ; un
    // appelant non privilégié ne voit que les relations du corpus publié
    // (garde anti-fuite dans DocumentRelationController, cf. GuardsUnpublishedDocuments).
    Route::get('articles/{article}/relations', [DocumentRelationController::class, 'index']);
    Route::get('relations/search', [DocumentRelationController::class, 'searchTargets']);

    // BE2 - Flat List Download : déclaré plus haut sous `throttle:corpus_read`.

    // BE4 - PDF Proxy : déclaré plus haut sous `throttle:corpus_read`, comme
    // `download` — c'est la même nature d'opération (lecture froide d'un PDF
    // du corpus publié, streamé depuis MinIO).

    // BE5 - PDF Export — verrouillé derrière l'entitlement `export`
    // (mibeko-dashboard#86) : Bearer Sanctum pro, ou signature valide mintée
    // par les endpoints export-token ci-dessus (voir EnsureExportEntitled).
    Route::get('legal-documents/{id}/export', [LegalDocumentExportController::class, 'export'])
        ->middleware(EnsureExportEntitled::class)
        ->name('legal-documents.export.signed');
    Route::get('articles/{id}/export', [LegalDocumentExportController::class, 'exportArticle'])
        ->middleware(EnsureExportEntitled::class)
        ->name('articles.export.signed');

    // Curation / Signalements (Mobile App) — endpoint public : le contrôleur
    // force source='report'/severity='info' (jamais bloquant) et le quota
    // dédié freine le remplissage anonyme de la file de triage.
    Route::post('reports', [CurationFlagController::class, 'store'])
        ->middleware('throttle:reports');

    // BE6 - Dossier PDF Export — endpoint public : les dossiers de l'app mobile
    // sont locaux et exportables sans compte (mode invité, versions déjà
    // publiées comprises). Les ids d'articles arrivant librement dans le corps
    // de la requête, le contrôleur restreint l'export au corpus publié pour
    // tout appelant sans rôle éditorial (cf. GuardsUnpublishedDocuments).
    Route::post('dossiers/export-pdf', [DossierExportController::class, 'exportPdf'])
        ->middleware('throttle:6,1');

    // ─── Espace Administration (/admin/*) — réservé au rôle admin ─────────────
    // Centre de gestion du dashboard React. Les référentiels (types de loi,
    // institutions, tags) y sont éditables au lieu de passer par un seeder.
    Route::middleware(['auth:sanctum', 'role:admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::get('overview', [AdminOverviewController::class, 'index']);

            Route::apiResource('document-types', AdminDocumentTypeController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('institutions', AdminInstitutionController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('tags', AdminTagController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            // ── Quotas IA par palier (mibeko-dashboard#95) ─────────────────────
            Route::get('ai-quota-tiers', [AdminAiQuotaTierController::class, 'index'])
                ->name('ai-quota-tiers.index');
            Route::put('ai-quota-tiers/{tier}', [AdminAiQuotaTierController::class, 'update'])
                ->name('ai-quota-tiers.update');
            Route::delete('ai-quota-tiers/{tier}', [AdminAiQuotaTierController::class, 'destroy'])
                ->name('ai-quota-tiers.destroy');

            // Triage des signalements (CurationFlag)
            Route::post('flags/bulk', [AdminCurationFlagController::class, 'bulk'])
                ->name('flags.bulk');
            Route::apiResource('flags', AdminCurationFlagController::class)
                ->only(['index', 'update', 'destroy']);

            // ── Gestion des utilisateurs ──────────────────────────────────────
            Route::get('users/stats', [AdminUserController::class, 'stats'])->name('users.stats');
            Route::post('users/{id}/restore', [AdminUserController::class, 'restore'])
                ->name('users.restore');
            Route::post('users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])
                ->name('users.password-reset');
            Route::post('users/{user}/revoke-tokens', [AdminUserController::class, 'revokeTokens'])
                ->name('users.revoke-tokens');
            Route::post('users/{user}/verify-email', [AdminUserController::class, 'verifyEmail'])
                ->name('users.verify-email');
            Route::delete('users/{user}/two-factor', [AdminUserController::class, 'disableTwoFactor'])
                ->name('users.two-factor.disable');
            Route::post('users/{user}/impersonate', [AdminImpersonationController::class, 'start'])
                ->name('users.impersonate');
            // mibeko-dashboard#95 : override de quota IA — vente manuelle, posé
            // par le fondateur, jamais un parcours self-service.
            Route::put('users/{user}/ai-quota-override', [AdminUserController::class, 'updateAiQuotaOverride'])
                ->name('users.ai-quota-override.update');
            Route::delete('users/{user}/ai-quota-override', [AdminUserController::class, 'destroyAiQuotaOverride'])
                ->name('users.ai-quota-override.destroy');
            Route::apiResource('users', AdminUserController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);

            // ── Invitations d'équipe ──────────────────────────────────────────
            Route::post('invitations/{invitation}/resend', [AdminUserInvitationController::class, 'resend'])
                ->name('invitations.resend');
            Route::apiResource('invitations', AdminUserInvitationController::class)
                ->only(['index', 'store', 'destroy']);

            // ── Journal d'activité (audit) ────────────────────────────────────
            Route::get('audits/stats', [AdminAuditController::class, 'stats'])->name('audits.stats');
            Route::get('audits/filters', [AdminAuditController::class, 'filters'])->name('audits.filters');
            Route::get('audits/export', [AdminAuditController::class, 'export'])->name('audits.export');
            Route::delete('audits', [AdminAuditController::class, 'purge'])->name('audits.purge');
            Route::get('audits', [AdminAuditController::class, 'index'])->name('audits.index');
            Route::get('audits/{audit}', [AdminAuditController::class, 'show'])->name('audits.show');
        });
});
