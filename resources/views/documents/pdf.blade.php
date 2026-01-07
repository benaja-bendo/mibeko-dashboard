<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $document->titre_officiel }}</title>
    <style>
        @page {
            margin: 2.5cm 2cm;
        }
        body { 
            font-family: 'Times New Roman', serif; 
            font-size: 11pt; 
            line-height: 1.5; 
            color: #333;
        }
        .header { 
            text-align: center; 
            margin-bottom: 40px; 
            border-bottom: 2px solid #1a56db; 
            padding-bottom: 20px; 
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #1a56db;
            margin-bottom: 10px;
            font-family: sans-serif;
            letter-spacing: 2px;
        }
        .republic {
            text-transform: uppercase;
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .doc-title {
            font-size: 18pt;
            font-weight: bold;
            margin: 20px 0;
            color: #000;
            text-transform: uppercase;
        }
        .meta { 
            color: #666; 
            font-size: 9pt; 
            margin-bottom: 30px;
            font-family: sans-serif;
        }
        
        /* Structure Hierarchy */
        .node { margin-top: 25px; margin-bottom: 15px; font-weight: bold; page-break-after: avoid; }
        .node-level-0 { 
            font-size: 14pt; 
            color: #1a56db; 
            text-transform: uppercase; 
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-top: 40px;
        }
        .node-level-1 { font-size: 12pt; color: #333; text-transform: uppercase; margin-top: 30px;}
        .node-level-2 { font-size: 11pt; font-style: italic; margin-top: 20px;}
        
        .article { 
            margin-bottom: 20px; 
            text-align: justify;
        }
        .article-num { 
            font-weight: bold; 
            margin-bottom: 5px; 
            color: #000;
            display: inline-block;
        }
        .article-content { 
            display: inline;
        }
        
        .footer { 
            position: fixed; 
            bottom: -1.5cm; 
            left: 0; 
            right: 0; 
            text-align: center; 
            font-size: 8pt; 
            color: #888; 
            border-top: 1px solid #eee; 
            padding-top: 10px; 
            font-family: sans-serif;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100pt;
            color: rgba(26, 86, 219, 0.05);
            z-index: -1000;
            pointer-events: none;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="watermark">MIBEKO</div>

    <div class="footer">
        Document généré par la plateforme Mibeko le {{ now()->format('d/m/Y') }} | {{ $document->institution?->nom ?? 'République du Congo' }}
    </div>

    <div class="header">
        <div class="logo">MIBEKO</div>
        <div class="republic">République du Congo</div>
        <div class="republic">{{ $document->institution?->nom }}</div>
        
        <h1 class="doc-title">{{ $document->titre_officiel }}</h1>
        
        <div class="meta">
            <strong>Type :</strong> {{ $document->type?->nom }} <br/>
            <strong>Date de signature :</strong> {{ $document->date_signature ? $document->date_signature->format('d/m/Y') : 'Non spécifiée' }}
        </div>
    </div>

    <div class="content">
        @foreach($document->structureNodes as $node)
            <div class="node node-level-{{ min($node->level ?? substr_count($node->tree_path ?? '', '.'), 2) }}">
                {{ $node->type_unite }} {{ $node->numero }} : {{ $node->titre }}
            </div>
            
            @foreach($document->articles->where('parent_node_id', $node->id) as $article)
                <div class="article">
                    <span class="article-num">Article {{ $article->numero_article }}</span> — 
                    <span class="article-content">{!! nl2br(e($article->activeVersion?->contenu_texte)) !!}</span>
                </div>
            @endforeach
        @endforeach
        
        {{-- Articles sans noeud parent --}}
        @foreach($document->articles->where('parent_node_id', null) as $article)
            <div class="article">
                <span class="article-num">Article {{ $article->numero_article }}</span> — 
                <span class="article-content">{!! nl2br(e($article->activeVersion?->contenu_texte)) !!}</span>
            </div>
        @endforeach
    </div>
</body>
</html>
