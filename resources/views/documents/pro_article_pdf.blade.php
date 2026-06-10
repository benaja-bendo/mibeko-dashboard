@extends('layouts.pro_pdf')

@section('title', "Article {$article->numero_article} - {$document->titre_officiel}")

@section('header_meta')
    <div>Extrait de : {{ \Illuminate\Support\Str::limit($document->titre_officiel, 50) }}</div>
@endsection

@section('content')
    <div class="main-content" style="position: relative; z-index: 1;">
        <div class="cover-badge" style="margin-bottom: 0.7cm;">Extrait officiel</div>

        {{-- Document source --}}
        <div class="no-break" style="border-left: 2.5pt solid #c8a86a; background-color: #faf7ef; padding: 12pt 14pt; margin-bottom: 1cm;">
            <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; color: #9a7b33; margin-bottom: 4pt;">
                {{ $document->type->nom ?? 'Document source' }}
                @if($document->institution)
                    &nbsp;·&nbsp;{{ $document->institution->nom }}
                @endif
            </div>
            <div style="font-family: 'DejaVu Serif', Georgia, serif; font-size: 12.5pt; font-weight: bold; color: #18140c; line-height: 1.4;">
                {{ $document->titre_officiel }}
            </div>
            @if($article->parentNode)
            <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #8d8678; margin-top: 5pt;">
                {{ $article->parentNode->type_unite }} {{ $article->parentNode->numero }}
                @if($article->parentNode->titre) — {{ $article->parentNode->titre }} @endif
            </div>
            @endif
        </div>

        {{-- Article --}}
        <div class="article-box" style="margin-top: 0.4cm;">
            <div class="section-title" style="margin-top: 0; font-size: 13pt;">
                <span class="section-kind" style="font-size: 10pt;">Article</span>&nbsp;{{ $article->numero_article }}
            </div>

            <div class="article-body" style="font-size: 11.5pt; line-height: 1.75;">
                {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
            </div>
        </div>

        {{-- Certification --}}
        <div class="certification">
            <p style="margin: 0;">Extrait certifié issu de la plateforme <strong>Mibeko — Le Droit numérique</strong>.</p>
            <p class="cert-id">ID Certification : {{ $article->id }}</p>
        </div>
    </div>
@endsection
