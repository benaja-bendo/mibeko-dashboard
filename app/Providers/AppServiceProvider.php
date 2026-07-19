<?php

namespace App\Providers;

use App\Ai\Storage\CompactingConversationStore;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Observers\LegalDocumentObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Remplace le store de conversation de laravel/ai par une version qui
        // ne rejoue pas les extraits complets des tours précédents au modèle
        // (cf. CompactingConversationStore) : contexte, coût et latence bornés.
        $this->app->singleton(ConversationStore::class, CompactingConversationStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        ArticleVersion::observe(ArticleVersionObserver::class);
        LegalDocument::observe(LegalDocumentObserver::class);

        Gate::define('viewApiDocs', function ($user = null) {
            // Autoriser tout le monde (ou mettre une condition spécifique, par ex: return true;)
            return true;
        });

        // Documentation API (Scramble) : déclare l'authentification par jeton
        // Bearer (Sanctum) au niveau du document OpenAPI, afin que la doc
        // affiche le schéma de sécurité et que le bouton « Try It » envoie le
        // header `Authorization: Bearer …`.
        Scramble::extendOpenApi(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer'));
        });

        RateLimiter::for('api', function (Request $request) {
            $limit = app()->environment('testing') ? 2 : 60;

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        // Autocomplétion de la recherche : appelée à la frappe (debounce côté
        // client), elle a son propre quota pour ne pas consommer celui de l'API.
        RateLimiter::for('search_suggest', function (Request $request) {
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });

        // Recherche publique du fonds (site vitrine, sans compte) : endpoint non
        // authentifié et requêtes SQL coûteuses (ILIKE + trigram) → quota par IP
        // pour protéger la base d'un abus, sans pénaliser l'usage humain normal.
        RateLimiter::for('search_public', function (Request $request) {
            return Limit::perMinute(app()->environment('testing') ? 5 : 30)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Signalements publics (POST /reports, app mobile sans compte) :
        // l'endpoint écrit en base sans authentification → quota serré par IP
        // (minute + jour) pour empêcher le remplissage en masse de la file de
        // triage. Un humain ne signale que quelques problèmes à la fois.
        RateLimiter::for('reports', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            $response = function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Trop de signalements envoyés. Réessayez plus tard.',
                    'errors' => null,
                ], 429);
            };

            return [
                Limit::perMinute(5)->by('minute:'.$key)->response($response),
                Limit::perDay(30)->by('day:'.$key)->response($response),
            ];
        });

        // Réinitialisation de mot de passe : quota serré par email + IP pour
        // empêcher l'envoi en masse et le brute-force du code OTP.
        RateLimiter::for('password_reset', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        // Connexion : quota serré par combinaison email + IP — freine le
        // brute-force d'un compte précis sans pénaliser les autres comptes
        // derrière la même adresse (NAT, cybercafé). Sert aussi le POST /login
        // web de Fortify (config fortify.limiters.login) : ne pas redéclarer
        // ce limiteur dans FortifyServiceProvider, il écraserait celui-ci.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            )->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Trop de tentatives de connexion. Réessayez dans une minute.',
                    'errors' => null,
                ], 429);
            });
        });

        // Rate limiter spécifique pour l'IA basé sur les rôles (Spatie) ou statuts.
        // Deux plafonds par utilisateur : par minute (confort d'usage) et par
        // JOUR (maîtrise du coût LLM — cf. config/ai.php `quotas`). Les admins
        // n'ont pas de limite par minute mais gardent un plafond journalier :
        // un jeton admin compromis ne doit pas générer une facture illimitée.
        RateLimiter::for('ai_assistant', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(5)->by($request->ip());
            }

            $dailyResponse = function () {
                return response()->json(['message' => 'Plafond journalier de requêtes IA atteint. Réessayez demain.'], 429);
            };

            if ($user->hasRole('admin')) {
                return [
                    Limit::perDay(config('ai.quotas.admin.per_day'))
                        ->by('day:'.$user->id)
                        ->response($dailyResponse),
                ];
            }

            // Utilisateurs pro/premium (si tu as un rôle premium)
            if ($user->hasRole('premium')) {
                return [
                    Limit::perMinute(config('ai.quotas.premium.per_minute'))->by('minute:'.$user->id)->response(function () {
                        return response()->json(['message' => 'Limite de requêtes IA atteinte pour votre abonnement Premium.'], 429);
                    }),
                    Limit::perDay(config('ai.quotas.premium.per_day'))->by('day:'.$user->id)->response($dailyResponse),
                ];
            }

            // Utilisateurs standards
            return [
                Limit::perMinute(config('ai.quotas.standard.per_minute'))->by('minute:'.$user->id)->response(function () {
                    return response()->json(['message' => 'Limite de requêtes IA atteinte. Passez à un abonnement supérieur pour plus de requêtes.'], 429);
                }),
                Limit::perDay(config('ai.quotas.standard.per_day'))->by('day:'.$user->id)->response($dailyResponse),
            ];
        });

        Storage::extend('gdrive', function ($app, $config) {
            $client = new GoogleClient;
            $client->setClientId($config['client_id'] ?? '');
            $client->setClientSecret($config['client_secret'] ?? '');

            $refreshToken = $config['refresh_token'] ?? '';
            if (str_starts_with($refreshToken, 'ya29.')) {
                throw new \Exception("ERREUR: Le GOOGLE_DRIVE_REFRESH_TOKEN configuré semble être un Access Token (commence par ya29.) qui expire en 1 heure. Vous devez utiliser un vrai Refresh Token (qui commence généralement par 1//). Lancez 'php artisan gdrive:token' pour en générer un nouveau.");
            }

            $client->refreshToken($refreshToken);

            // Forcer le client à aller chercher un access token valide
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($token['error'])) {
                throw new \Exception('Google Drive Auth Error: '.($token['error_description'] ?? $token['error']).". Veuillez regénérer votre token avec 'php artisan gdrive:token'.");
            }

            $client->setApplicationName($config['app_name'] ?? config('app.name'));

            $service = new Drive($client);

            $adapterOptions = [
                'useDisplayPaths' => (bool) ($config['use_display_paths'] ?? true),
                'parameters' => array_filter([
                    'quotaUser' => $config['quota_user'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''),
            ];

            if (! empty($config['team_drive_id'])) {
                $adapterOptions['teamDriveId'] = $config['team_drive_id'];
            }

            if (! empty($config['shared_folder_id'])) {
                $adapterOptions['sharedFolderId'] = $config['shared_folder_id'];
            }

            $adapter = new GoogleDriveAdapter($service, $config['root'] ?? null, $adapterOptions);

            if (! empty($config['supports_all_drives'])) {
                $adapter->enableTeamDriveSupport();
            }

            $filesystem = new Filesystem($adapter);

            return new FilesystemAdapter($filesystem, $adapter, $config);
        });
    }
}
