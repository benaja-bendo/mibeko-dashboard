<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Mibeko</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fcf9f8; color: #1b1c1c; font-family: system-ui, sans-serif; }
        main { width: min(32rem, calc(100% - 3rem)); padding: 2rem; background: #ffffff; border-radius: 8px; border: 1px solid #c1c8c2; text-align: center; }
        h1 { margin-top: 0; }
        p { color: #414844; line-height: 1.6; }
        a { color: #03271a; }
        a.bouton { display: inline-block; margin-top: .5rem; padding: .75rem 1rem; border-radius: 8px; background: #03271a; color: #ffffff; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
