<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecureApiHeaders;
use App\Http\Middleware\UpdateLastSeen;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        // Le contenu juridique d'un dossier de travail est une transcription
        // source : ses blancs externes font partie de la proposition mesurée.
        // Sans cette exception, TrimStrings modifie le JSON avant même que le
        // contrôleur puisse calculer son empreinte ou afficher son diff.
        $middleware->trimStrings(except: ['target.articles.*.content']);

        // Hôte API « machine » : en-têtes de sécurité + anti-indexation partout.
        $middleware->append(SecureApiHeaders::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            UpdateLastSeen::class,
        ]);

        // mibeko-dashboard#129 : mibeko-front s'authentifie en Bearer Sanctum,
        // donc passe par ce groupe et jamais par `web` — sans ce câblage,
        // `last_seen_at` n'était mis à jour que par les pages Inertia mortes.
        $middleware->api(append: [
            UpdateLastSeen::class,
        ]);

        // La résolution de l'utilisateur (`auth:sanctum`) doit avoir eu lieu
        // avant qu'on lise Auth::user() ; sans cette priorité explicite, le
        // groupe `api` place ce middleware avant le middleware de route qui
        // authentifie la requête.
        $middleware->appendToPriorityList(
            after: AuthenticatesRequests::class,
            append: UpdateLastSeen::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
