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
    
    <!-- App Links for Android -->
    <meta property="al:android:package" content="com.mibeko.mibeko">
    <meta property="al:android:url" content="mibeko://document/{{ $document->id }}">
    <meta property="al:android:app_name" content="Mibeko">
    
    <!-- Smart App Banner for iOS -->
    <meta name="apple-itunes-app" content="app-id=YOUR_APP_STORE_ID, app-argument=mibeko://document/{{ $document->id }}">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .logo {
            width: 80px;
            margin-bottom: 24px;
        }
        .type-badge {
            display: inline-block;
            background-color: #dbeafe;
            color: #1e40af;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 24px;
            color: #0f172a;
            line-height: 1.4;
        }
        .btn {
            background-color: #2563eb;
            color: white;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
            margin-bottom: 16px;
            width: 100%;
            box-sizing: border-box;
        }
        .btn:hover {
            background-color: #1d4ed8;
        }
        .footer {
            margin-top: 24px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('logo.svg') }}" alt="Mibeko Logo" class="logo">
        <div class="type-badge">{{ $document->type->nom }}</div>
        <h1>{{ $document->titre_officiel }}</h1>
        
        <a href="mibeko://document/{{ $document->id }}" class="btn">
            Ouvrir dans l'application Mibeko
        </a>
        
        <div class="footer">
            &copy; {{ date('Y') }} Mibeko - Le Droit numérique
        </div>
    </div>
</body>
</html>
