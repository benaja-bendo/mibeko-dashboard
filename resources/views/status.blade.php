<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $service }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #fcf9f8; color: #1b1c1c;
        }
        .card {
            max-width: 34rem; width: 100%; padding: 2.5rem; border-radius: 8px; background: #ffffff;
            border: 1px solid #c1c8c2;
        }
        .badge {
            display: inline-block; font-size: .72rem; letter-spacing: .05em; text-transform: uppercase;
            color: #03271a; background: #e6f0ea; padding: .3rem .65rem; border-radius: 4px;
        }
        h1 { font-size: 1.4rem; margin: 1rem 0 .35rem; font-weight: 600; }
        p { color: #414844; line-height: 1.6; margin: .35rem 0; }
        .links { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1.4rem; }
        .links a {
            text-decoration: none; font-size: .9rem; font-weight: 500;
            color: #03271a; background: #e6f0ea;
            padding: .55rem .9rem; border-radius: 8px; border: 1px solid #c1c8c2;
        }
        .links a:hover { background: #c1c8c2; }
        .meta { margin-top: 1.4rem; padding-top: 1.1rem; border-top: 1px solid #c1c8c2; font-size: .85rem; color: #676d69; }
        .dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 50%; background: #03271a; margin-right: .4rem; vertical-align: middle; }
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
