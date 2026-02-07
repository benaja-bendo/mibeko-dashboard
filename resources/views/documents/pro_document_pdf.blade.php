@extends('layouts.pro_pdf')

@section('title', $document->titre_officiel)

@section('header_meta')
    {{ $document->type->nom ?? 'Document' }}
@endsection

@section('content')
    <!-- Cover Page -->
    <div class="cover-page">
        <div class="cover-content">
            <div class="cover-badge">{{ $document->type->nom ?? 'Législation' }}</div>
            <h1 class="cover-title">{{ $document->titre_officiel }}</h1>

            <div class="cover-info">
                <table class="document-info-table" style="width: 100%; border-collapse: collapse; margin-top: 1cm;">
                    @if($document->institution)
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; width: 40%; text-align: left;">Institution</td>
                        <td style="padding: 10pt; text-align: left;">{{ $document->institution->nom }}</td>
                    </tr>
                    @endif
                    @if($document->date_signature)
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; text-align: left;">Date de signature</td>
                        <td style="padding: 10pt; text-align: left;">{{ $document->date_signature->format('d/m/Y') }}</td>
                    </tr>
                    @endif
                    @if($document->reference_nor)
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; text-align: left;">Référence</td>
                        <td style="padding: 10pt; text-align: left;">{{ $document->reference_nor }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding: 10pt; font-weight: bold; color: #1565C0; text-align: left;">Statut</td>
                        <td style="padding: 10pt; text-align: left;">Officiel - En vigueur</td>
                    </tr>
                </table>
            </div>

            <div style="position: absolute; bottom: 2cm; left: 0; right: 0; font-family: 'DejaVu Sans', sans-serif; font-weight: bold; color: #1565C0; letter-spacing: 1px;">
                MINISTÈRE DE LA JUSTICE, DES DROITS HUMAINS<br>ET DE LA PROMOTION DES PEUPLES
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" style="position: relative; z-index: 1;">
        @foreach($document->structureNodes as $node)
            @php
                $depth = substr_count($node->tree_path, '.');
                $marginLeft = $depth * 15;
            @endphp

            <div class="section-title" style="margin-left: {{ $marginLeft }}px;">
                {{ $node->type_unite }} {{ $node->numero }} : {{ $node->titre }}
            </div>

            @php
                $nodeArticles = $document->articles->where('parent_node_id', $node->id)->sortBy('ordre_affichage');
            @endphp

            @foreach($nodeArticles as $article)
                <div class="article-box" style="margin-left: {{ $marginLeft + 15 }}px;">
                    <span class="article-head">Article {{ $article->numero_article }}</span>
                    <div class="article-body">
                        {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                    </div>
                </div>
            @endforeach
        @endforeach

        @php
            $orphanArticles = $document->articles->whereNull('parent_node_id')->sortBy('ordre_affichage');
        @endphp

        @if($orphanArticles->count() > 0)
            <div class="section-title">Dispositions complémentaires</div>
            @foreach($orphanArticles as $article)
                <div class="article-box">
                    <span class="article-head">Article {{ $article->numero_article }}</span>
                    <div class="article-body">
                        {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
