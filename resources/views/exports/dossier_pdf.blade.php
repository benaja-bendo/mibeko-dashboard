<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'Checkbook', sans-serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #1565C0;
            padding-bottom: 15px;
        }
        .header h1 {
            color: #1565C0;
            margin: 0;
            font-size: 24pt;
        }
        .meta {
            color: #666;
            font-size: 10pt;
            margin-top: 5px;
        }
        .description {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 30px;
            font-style: italic;
        }
        .item {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .article-header {
            font-weight: bold;
            font-size: 14pt;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .article-context {
            font-size: 9pt;
            color: #7f8c8d;
            margin-bottom: 10px;
        }
        .article-content {
            text-align: justify;
        }
        .personal-note {
            margin-top: 10px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            font-size: 10pt;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #aaa;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="meta">Généré par Mibeko le {{ $generated_at }}</div>
    </div>

    @if($description)
    <div class="description">
        {{ $description }}
    </div>
    @endif

    @foreach($items as $item)
        @if($item['type'] === 'article')
            @php $article = $item['content']; @endphp
            <div class="item">
                <div class="article-header">
                    Document: {{ $article->document->titre_officiel ?? 'Inconnu' }}
                    <br>
                    Article {{ $article->numero_article }}
                </div>
                <div class="article-context">
                    {{ $article->breadcrumb }}
                </div>
                <div class="article-content">
                    {!! nl2br(e($article->activeVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                </div>
                
                @if($item['note'])
                <div class="personal-note">
                    <strong>Note personnelle:</strong> {{ $item['note'] }}
                </div>
                @endif
            </div>
            <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
        @endif
    @endforeach

    <div class="footer">
        Document généré via l'application Mibeko - Le Droit à portée de main
    </div>
</body>
</html>
