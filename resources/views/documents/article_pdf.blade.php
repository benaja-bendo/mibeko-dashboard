<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Article {{ $article->numero_article }} - {{ $document->titre_officiel }}</title>
    <style>
        @page {
            margin: 2.5cm 2cm;
        }
        body { 
            font-family: 'Times New Roman', serif; 
            font-size: 12pt; 
            line-height: 1.6; 
            color: #333;
        }
        .header { 
            margin-bottom: 30px; 
            border-bottom: 1px solid #1a56db; 
            padding-bottom: 15px; 
        }
        .logo {
            font-size: 20px;
            font-weight: bold;
            color: #1a56db;
            font-family: sans-serif;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .breadcrumb {
            font-size: 9pt;
            color: #666;
            font-family: sans-serif;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .doc-title {
            font-size: 14pt;
            font-weight: bold;
            color: #000;
            margin: 0;
        }
        
        .article-container {
            background-color: #fcfcfc;
            border-left: 4px solid #1a56db;
            padding: 20px;
            margin: 30px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .article-header {
            font-size: 16pt;
            font-weight: bold;
            color: #1a56db;
            margin-bottom: 15px;
            border-bottom: 1px dashed #ddd;
            padding-bottom: 10px;
        }
        .article-content {
            text-align: justify;
            white-space: pre-wrap;
        }
        
        .metadata {
            margin-top: 40px;
            font-size: 9pt;
            color: #777;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .metadata-item {
            margin-bottom: 5px;
        }
        
        .footer { 
            position: fixed; 
            bottom: -1.5cm; 
            left: 0; 
            right: 0; 
            text-align: center; 
            font-size: 8pt; 
            color: #888; 
            font-family: sans-serif;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">MIBEKO</div>
        <div class="breadcrumb">
            {{ $document->type?->nom ?? 'Texte' }} > 
            {{ \Illuminate\Support\Str::limit($document->titre_officiel, 50) }}
            @if($article->parentNode)
             > {{ $article->parentNode->titre ?? $article->parentNode->numero }}
            @endif
        </div>
        <h2 class="doc-title">{{ $document->titre_officiel }}</h2>
    </div>

    <div class="article-container">
        <div class="article-header">
            Article {{ $article->numero_article }}
        </div>
        <div class="article-content">
            {{ $article->activeVersion?->contenu_texte ?? "Contenu non disponible." }}
        </div>
    </div>

    <div class="metadata">
        <div class="metadata-item"><strong>Source :</strong> {{ $document->institution?->nom ?? 'Journal Officiel' }}</div>
        <div class="metadata-item"><strong>Date de signature du texte :</strong> {{ $document->date_signature ? $document->date_signature->format('d/m/Y') : 'N/A' }}</div>
        <div class="metadata-item"><strong>Dernière mise à jour :</strong> {{ $article->updated_at->format('d/m/Y') }}</div>
        <br>
        <div class="metadata-item" style="font-style: italic;">
            Ce document est fourni à titre d'information par la plateforme Mibeko. Seuls les textes publiés au Journal Officiel font foi.
        </div>
    </div>

    <div class="footer">
        Généré par Mibeko le {{ now()->format('d/m/Y à H:i') }}
    </div>
</body>
</html>
