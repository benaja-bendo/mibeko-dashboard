<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->titre_officiel }} - Mibeko</title>

    <!-- Open Graph / Social Media -->
    <meta property="og:title" content="{{ $document->titre_officiel }}">
    <meta property="og:description" content="{{ $document->type->nom }} - Consultez ce document complet sur Mibeko, votre plateforme de droit numérique.">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('logo.svg') }}">
    <meta property="og:site_name" content="Mibeko">

    @if(!empty($canonical))
    <link rel="canonical" href="{{ $canonical }}">
    @endif

    <!-- App Links for Android -->
    <meta property="al:android:package" content="{{ $androidPackage }}">
    <meta property="al:android:url" content="mibeko://document/{{ $document->id }}">
    <meta property="al:android:app_name" content="Mibeko">

    <!-- Smart App Banner for iOS -->
    <meta name="apple-itunes-app" content="app-id={{ $iosAppId }}, app-argument=mibeko://document/{{ $document->id }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap" rel="stylesheet">

    <style>
        :root {
            --color-primary: #03271a;
            --color-on-primary: #ffffff;
            --color-primary-container: #1b3d2f;
            --color-secondary: #8f4c31;
            --color-background: #fcf9f8;
            --color-surface-container-lowest: #ffffff;
            --color-surface-container: #f0eded;
            --color-on-surface: #1b1c1c;
            --color-on-surface-variant: #414844;
            --color-outline: #727974;
            --color-outline-variant: #c1c8c2;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--color-background);
            color: var(--color-on-surface);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: var(--color-surface-container-lowest);
            border: 1px solid var(--color-outline-variant);
            border-radius: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .logo {
            width: 64px;
            margin-bottom: 20px;
        }
        .type-badge {
            display: inline-block;
            background-color: rgba(143, 76, 49, 0.1);
            color: var(--color-secondary);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.025em;
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        h1 {
            font-family: 'Source Serif 4', Georgia, serif;
            font-size: 21px;
            font-weight: 600;
            margin: 0 0 28px;
            color: var(--color-on-surface);
            line-height: 1.4;
        }
        .btn {
            background-color: var(--color-primary);
            color: var(--color-on-primary);
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 4px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
            margin-bottom: 14px;
            width: 100%;
            box-sizing: border-box;
        }
        .btn:hover {
            background-color: var(--color-primary-container);
        }
        .btn-secondary {
            background-color: transparent;
            color: var(--color-on-surface);
            border: 1px solid var(--color-outline);
        }
        .btn-secondary:hover {
            background-color: var(--color-surface-container);
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: var(--color-outline);
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('logo.svg') }}" alt="Mibeko Logo" class="logo">
        <div class="type-badge">{{ $document->type->nom }}</div>
        <h1>{{ $document->titre_officiel }}</h1>

        @if($platform === 'android')
        <a
            href="intent://document/{{ $document->id }}#Intent;scheme=mibeko;package={{ $androidPackage }};S.browser_fallback_url={{ rawurlencode($androidStoreUrl) }};end"
            class="btn"
        >
            Ouvrir dans l'application Mibeko
        </a>
        @elseif($platform === 'ios')
        <a href="mibeko://document/{{ $document->id }}" id="app-btn" data-store-url="{{ $iosStoreUrl }}" class="btn">
            Ouvrir dans l'application Mibeko
        </a>
        @endif

        @if(!empty($canonical))
        <a href="{{ $canonical }}" class="btn {{ $platform === 'other' ? '' : 'btn-secondary' }}">
            Lire sur mibeko.fr
        </a>
        @endif

        <div class="footer">
            &copy; {{ date('Y') }} Mibeko - Le Droit numérique
        </div>
    </div>

    @if($platform === 'ios')
    <script>
        (function () {
            var btn = document.getElementById('app-btn');
            if (!btn) {
                return;
            }
            var storeUrl = btn.getAttribute('data-store-url');
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                var fallbackTimer = setTimeout(function () {
                    window.location.href = storeUrl;
                }, 1500);
                var cancelFallback = function () {
                    clearTimeout(fallbackTimer);
                };
                window.addEventListener('pagehide', cancelFallback);
                document.addEventListener('visibilitychange', function () {
                    if (document.hidden) {
                        cancelFallback();
                    }
                });
                window.location.href = btn.getAttribute('href');
            });
        })();
    </script>
    @endif
</body>
</html>
