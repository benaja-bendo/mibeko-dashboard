<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $service }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f7f5ef; color: #1b2a23;
        }
        .card {
            max-width: 34rem; width: 100%; padding: 2.5rem; border-radius: 1rem; background: #ffffff;
            border: 1px solid rgba(30, 107, 71, .12); box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        }
        .badge {
            display: inline-block; font-size: .72rem; letter-spacing: .05em; text-transform: uppercase;
            color: #1e6b47; background: rgba(30, 107, 71, .10); padding: .3rem .65rem; border-radius: 999px;
        }
        h1 { font-size: 1.4rem; margin: 1rem 0 .35rem; font-weight: 600; }
        p { color: #4b5a52; line-height: 1.6; margin: .35rem 0; }
        .links { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1.4rem; }
        .links a {
            text-decoration: none; font-size: .9rem; font-weight: 500;
            color: #1e6b47; background: rgba(30, 107, 71, .08);
            padding: .55rem .9rem; border-radius: .6rem; border: 1px solid rgba(30, 107, 71, .14);
        }
        .links a:hover { background: rgba(30, 107, 71, .14); }
        .meta { margin-top: 1.4rem; padding-top: 1.1rem; border-top: 1px solid rgba(0, 0, 0, .06); font-size: .85rem; color: #6b7a72; }
        .dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 50%; background: #1e6b47; margin-right: .4rem; vertical-align: middle; }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">Mibeko · API</span>
        <h1>{{ $service }}</h1>
        <p>Point d'entrée applicatif (REST) des services Mibeko, consommé par
        l'application web et mobile. Cette adresse n'est pas un site : pour utiliser
        Mibeko, rendez-vous sur l'application.</p>
        <div class="links">
            <a href="{{ $documentation }}">Documentation API</a>
            <a href="{{ $application }}">Ouvrir l'application</a>
            <a href="{{ $website }}">Site officiel</a>
        </div>
        <p class="meta">
            <span class="dot"></span>État&nbsp;: <strong>opérationnel</strong> · version {{ $version }}
        </p>
    </main>
</body>
</html>
