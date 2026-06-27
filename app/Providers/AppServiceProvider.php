<?php

namespace App\Providers;

use App\Models\ArticleVersion;
use App\Observers\ArticleVersionObserver;
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
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        ArticleVersion::observe(ArticleVersionObserver::class);

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

        // Réinitialisation de mot de passe : quota serré par email + IP pour
        // empêcher l'envoi en masse et le brute-force du code OTP.
        RateLimiter::for('password_reset', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        // Rate limiter spécifique pour l'IA basé sur les rôles (Spatie) ou statuts
        RateLimiter::for('ai_assistant', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(5)->by($request->ip());
            }

            // Les administrateurs n'ont pas de limite
            if ($user->hasRole('admin')) {
                return Limit::none();
            }

            // Utilisateurs pro/premium (si tu as un rôle premium)
            if ($user->hasRole('premium')) {
                return Limit::perMinute(60)->by($user->id)->response(function () {
                    return response()->json(['message' => 'Limite de requêtes IA atteinte pour votre abonnement Premium.'], 429);
                });
            }

            // Utilisateurs standards
            return Limit::perMinute(20)->by($user->id)->response(function () {
                return response()->json(['message' => 'Limite de requêtes IA atteinte. Passez à un abonnement supérieur pour plus de requêtes.'], 429);
            });
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
