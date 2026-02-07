@extends('layouts.pro_pdf')

@section('title', $title)

@section('header_meta')
    Dossier Personnel
@endsection

@section('content')
    <!-- Cover Page -->
    <div class="cover-page">
        <div class="cover-content">
            <div class="cover-badge">Dossier Juridique Personnel</div>
            <h1 class="cover-title">{{ $title }}</h1>

            <div style="margin: 2cm 0; font-size: 11pt; color: #444; line-height: 1.6; text-align: center; font-style: italic; padding: 0 1cm;">
                {{ $description ?: 'Ce dossier regroupe une sélection d\'articles pour étude et consultation.' }}
            </div>

            <div class="cover-info">
                <table class="document-info-table" style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; width: 45%; text-align: left;">Articles sélectionnés</td>
                        <td style="padding: 10pt; text-align: left;">{{ count($items) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; text-align: left;">Date de génération</td>
                        <td style="padding: 10pt; text-align: left;">{{ $generated_at }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; text-align: left;">Source</td>
                        <td style="padding: 10pt; text-align: left;">Mibeko - Le Droit numérique</td>
                    </tr>
                </table>
            </div>

            <div style="position: absolute; bottom: 2cm; left: 0; right: 0; font-family: 'DejaVu Sans', sans-serif; font-weight: bold; color: #1565C0; letter-spacing: 1px;">
                DOCUMENT DE TRAVAIL PERSONNEL
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" style="position: relative; z-index: 1;">
        @foreach($items as $item)
            @if($item['type'] === 'article')
                @php $article = $item['content']; @endphp
                <div class="article-box" style="margin-bottom: 40pt; border-bottom: 0.5pt solid #eee; padding-bottom: 20pt;">
                    <div style="background-color: #f8f9fa; border-left: 3pt solid #1565C0; padding: 8pt 12pt; margin-bottom: 12pt;">
                        <div style="font-size: 8pt; color: #1565C0; font-weight: bold; text-transform: uppercase; margin-bottom: 3pt;">
                            Source : {{ $article->document->type->nom ?? 'Document' }}
                        </div>
                        <div style="font-size: 10pt; color: #000; font-weight: bold; font-family: 'DejaVu Sans', sans-serif;">
                            {{ $article->document->titre_officiel ?? 'Document Source' }}
                        </div>
                    </div>

                    <span class="article-head" style="font-size: 12pt;">Article {{ $article->numero_article }}</span>

                    @if($article->parentNode)
                    <div style="font-size: 9pt; color: #7f8c8d; margin-bottom: 12pt; font-style: italic;">
                        Emplacement : {{ $article->parentNode->type_unite }} {{ $article->parentNode->numero }} - {{ $article->parentNode->titre }}
                    </div>
                    @endif

                    <div class="article-body">
                        {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                    </div>

                    @if($item['note'])
                    <div style="margin-top: 15pt; background-color: #fff9c4; border: 1pt dashed #fbc02d; padding: 12pt; border-radius: 4pt;">
                        <div style="font-size: 8pt; font-weight: bold; text-transform: uppercase; margin-bottom: 4pt; color: #9a7d0e;">Note personnelle :</div>
                        <div style="font-style: italic; font-size: 10pt; color: #333;">{{ $item['note'] }}</div>
                    </div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
@endsection
