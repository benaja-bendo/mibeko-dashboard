@extends('layouts.pro_pdf')

@section('title', "Article {$article->numero_article} - {$document->titre_officiel}")

@section('header_meta')
    Extrait de: {{ \Illuminate\Support\Str::limit($document->titre_officiel, 40) }}
@endsection

@section('content')
    <div class="main-content" style="margin-top: 0.5cm; position: relative; z-index: 1;">
        <div class="cover-badge" style="margin-bottom: 0.8cm;">Extrait Officiel</div>

        <div style="margin-bottom: 1.5cm; border-left: 4pt solid #1565C0; padding-left: 15pt; background-color: #f8f9fa; padding-top: 10pt; padding-bottom: 10pt;">
            <div style="font-size: 9pt; color: #1565C0; font-weight: bold; text-transform: uppercase; margin-bottom: 5pt; font-family: 'DejaVu Sans', sans-serif;">
                {{ $document->type->nom ?? 'Document Source' }}
            </div>
            <div style="font-size: 13pt; font-weight: bold; color: #000; line-height: 1.4; font-family: 'DejaVu Sans', sans-serif;">
                {{ $document->titre_officiel }}
            </div>
        </div>

        <div class="article-box" style="margin-top: 1cm;">
            <div class="section-title" style="margin-top: 0; border-bottom: 2pt solid #1565C0;">
                Article {{ $article->numero_article }}
            </div>

            @if($article->parentNode)
            <div style="font-size: 10pt; color: #7f8c8d; margin-bottom: 25pt; font-style: italic; border-bottom: 0.5pt dashed #ccc; padding-bottom: 10pt;">
                Ubications : {{ $article->parentNode->type_unite }} {{ $article->parentNode->numero }} - {{ $article->parentNode->titre }}
            </div>
            @endif

            <div class="article-body" style="font-size: 12pt; line-height: 1.7;">
                {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
            </div>
        </div>

        <div style="margin-top: 4cm; border-top: 1pt solid #1565C0; padding-top: 15pt; font-size: 9pt; color: #666; text-align: center;">
            <p>Ce document est un extrait certifié issu de la plateforme <strong>Mibeko - Le Droit numérique</strong>.</p>
            <p style="font-family: monospace; font-size: 8pt; margin-top: 5pt; color: #999;">ID Certification : {{ $article->id }}</p>
        </div>
    </div>
@endsection
