<?php

namespace App\Providers;

use App\Ai\AiRouteName;
use App\Ai\AiUsageLogger;
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

        // Lecture froide du corpus (catalogue, documents, arbre, téléchargement).
        // La synchronisation initiale d'un téléphone enchaîne ~1 + N requêtes
        // (une par document) : sous le quota générique de 60/min elle serait
        // hachée de 429 et laisserait un corpus incomplet. Le quota est donc
        // large, et surtout clé par APPAREIL quand l'en-tête est fourni : au
        // Congo, le CGNAT des opérateurs fait partager une même IP publique à
        // des centaines d'abonnés, qui se pénaliseraient mutuellement.
        // Le contenu servi est du droit publié, déjà public sur le site : le
        // quota protège le coût serveur, pas un secret.
        RateLimiter::for('corpus_read', function (Request $request) {
            $identity = $request->user()?->id
                ?: ($request->header('X-Mibeko-Device')
                    ? 'device:'.sha1($request->header('X-Mibeko-Device'))
                    : $request->ip());

            return [
                Limit::perMinute(app()->environment('testing') ? 60 : 300)->by($identity),
                // Garde-fou : empêche une seule IP de saturer le serveur en
                // faisant tourner de faux identifiants d'appareil.
                Limit::perMinute(app()->environment('testing') ? 120 : 1200)->by('corpus_ip:'.$request->ip()),
            ];
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

        // Enregistrement d'un appareil (POST /devices/register, route publique
        // qui ÉCRIT en base). Le quota générique `api` (60/min, clé IP pour un
        // invité) est ici doublement inadapté : trop large pour une écriture
        // anonyme, et clé par IP alors que le CGNAT des opérateurs congolais
        // fait partager une même adresse à des centaines d'abonnés — un seul
        // téléphone bavard bloquerait tous les autres.
        // Clé principale : l'identifiant d'appareil, comme le limiteur
        // `corpus_read` déjà en place. Un téléphone n'enregistre son jeton qu'au
        // démarrage et à chaque rotation FCM : 5/min laisse de la marge aux
        // reprises réseau tout en fermant la porte au martèlement. Le second
        // plafond, par IP, borne la fabrication en masse de faux appareils sans
        // pénaliser un cybercafé.
        RateLimiter::for('device_register', function (Request $request) {
            $deviceId = (string) $request->input('device_id');
            $identity = $deviceId !== ''
                ? 'device:'.sha1($deviceId)
                : 'ip:'.$request->ip();

            $response = function () {
                return response()->json([
                    'success' => false,
                    'message' => "Trop d'enregistrements d'appareil. Réessayez dans une minute.",
                    'errors' => null,
                ], 429);
            };

            return [
                Limit::perMinute(5)->by($identity)->response($response),
                Limit::perMinute(app()->environment('testing') ? 60 : 120)
                    ->by('device_register_ip:'.$request->ip())
                    ->response($response),
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
        // Deux plafonds par utilisateur : par minute (confort d'usage) et une
        // allocation de fond (maîtrise du coût LLM — cf. config/ai.php
        // `quotas`) — MENSUELLE pour le palier gratuit `standard`, journalière
        // pour `user_pro` et `admin` (mibeko-dashboard#62 : un plafond
        // journalier gratuit se lit comme illimité et ne crée aucun moment
        // d'achat). Les admins n'ont pas de limite par minute mais gardent un
        // plafond journalier : un jeton admin compromis ne doit pas générer
        // une facture illimitée.
        //
        // Les messages 429 doivent rester NEUTRES — aucune mention d'abonnement
        // ou d'achat : ils s'affichent tels quels dans le chat de l'app mobile,
        // et une incitation à payer sans achat in-app violerait la règle 3.1.1
        // de l'App Store. Le client s'appuie sur le champ machine `code`/`scope`
        // et le header standard Retry-After (transmis via `$headers`).
        RateLimiter::for('ai_assistant', function (Request $request) {
            // mibeko-dashboard#61 : « un 429 est une donnée » — journalisé ici,
            // seul endroit qui voit un refus de quota avant le contrôleur.
            $log = function (Request $request) {
                if ($route = AiRouteName::fromRequest($request)) {
                    app(AiUsageLogger::class)->rateLimited($request->user(), $route);
                }
            };

            $minuteResponse = function (Request $request, array $headers) use ($log) {
                $log($request);

                return response()->json([
                    'message' => 'Limite temporaire de requêtes atteinte. Réessayez dans quelques minutes.',
                    'code' => 'AI_RATE_LIMITED',
                    'scope' => 'minute',
                ], 429, $headers);
            };

            $dailyResponse = function (Request $request, array $headers) use ($log) {
                $log($request);

                return response()->json([
                    'message' => 'Plafond journalier de requêtes IA atteint. Réessayez demain.',
                    'code' => 'AI_RATE_LIMITED',
                    'scope' => 'day',
                ], 429, $headers);
            };

            // mibeko-dashboard#62 : le palier gratuit est désormais mensuel
            // (fenêtre glissante de 30 jours, Limit::perDay(..., 30)) — un
            // plafond journalier se lisait comme illimité et ne créait aucun
            // moment d'achat. Le message dit QUAND l'allocation revient
            // (calculé depuis Retry-After, déjà posé par ThrottleRequests),
            // sinon un plafond mensuel se lit comme un mur définitif.
            $monthResponse = function (Request $request, array $headers) use ($log) {
                $log($request);

                $days = isset($headers['Retry-After']) ? max(1, (int) ceil($headers['Retry-After'] / 86400)) : null;
                $delai = match (true) {
                    $days === null => 'dans les prochains jours',
                    $days === 1 => 'dans 1 jour',
                    default => "dans {$days} jours",
                };

                return response()->json([
                    'message' => "Plafond mensuel de requêtes IA atteint. Réessayez {$delai}.",
                    'code' => 'AI_RATE_LIMITED',
                    'scope' => 'month',
                ], 429, $headers);
            };

            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(5)->by($request->ip())->response($minuteResponse);
            }

            if ($user->hasRole('admin')) {
                return [
                    Limit::perDay(config('ai.quotas.admin.per_day'))
                        ->by('day:'.$user->id)
                        ->response($dailyResponse),
                ];
            }

            if ($user->hasRole('user_pro')) {
                return [
                    Limit::perMinute(config('ai.quotas.user_pro.per_minute'))
                        ->by('minute:'.$user->id)
                        ->response($minuteResponse),
                    Limit::perDay(config('ai.quotas.user_pro.per_day'))
                        ->by('day:'.$user->id)
                        ->response($dailyResponse),
                ];
            }

            return [
                Limit::perMinute(config('ai.quotas.standard.per_minute'))
                    ->by('minute:'.$user->id)
                    ->response($minuteResponse),
                Limit::perDay(config('ai.quotas.standard.per_month'), 30)
                    ->by('month:'.$user->id)
                    ->response($monthResponse),
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
