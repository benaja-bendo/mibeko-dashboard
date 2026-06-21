@extends('layouts.pro_pdf')

@section('title', $document->titre_officiel)

@section('header_meta')
    <div>{{ \Illuminate\Support\Str::limit($document->titre_officiel, 60) }}</div>
@endsection

@php
    $scopeLabels = [
        'national' => 'Droit congolais',
        'ohada' => 'OHADA',
        'communautaire' => 'Droit communautaire',
    ];

    // Le préambule (qualité du signataire, visas, considérants) est une feuille
    // de tête : on le sort des orphelins pour le rendre AVANT le dispositif,
    // pas sous « Dispositions complémentaires ».
    $preambleArticles = $orphans->filter(fn ($a) => $a->numero_article === 'PREAMBULE');
    $orphans = $orphans->reject(fn ($a) => $a->numero_article === 'PREAMBULE')->values();

    // Libellé lisible des feuilles techniques (préambule, tableau) vs vrais articles.
    $articleLabel = function (string $numero): string {
        if ($numero === 'PREAMBULE') {
            return 'Préambule';
        }
        if ($numero === 'SIGNATURE') {
            return 'Signature';
        }
        if (preg_match('/^TABLEAU_(\d+)$/', $numero, $m)) {
            return 'Tableau '.$m[1];
        }
        return 'Article '.$numero;
    };
@endphp

@section('content')
    {{-- Page de couverture --}}
    <div class="cover-page">
        <div class="cover-band">
            <div class="brand-mark">MIBEKO</div>
            <div class="brand-sub">Le Droit numérique</div>
        </div>

        <div class="cover-body">
            <div class="cover-badge">{{ $document->type->nom ?? 'Législation' }}</div>
            <h1 class="cover-title">{{ $document->titre_officiel }}</h1>
            <hr class="cover-rule">

            <table class="cover-info-table">
                @if($document->institution)
                <tr>
                    <td class="label">Institution</td>
                    <td>{{ $document->institution->nom }}</td>
                </tr>
                @endif
                @if($document->legal_scope && isset($scopeLabels[$document->legal_scope]))
                <tr>
                    <td class="label">Périmètre</td>
                    <td>{{ $scopeLabels[$document->legal_scope] }}</td>
                </tr>
                @endif
                @if($document->date_signature)
                <tr>
                    <td class="label">Date de signature</td>
                    <td>{{ $document->date_signature->format('d/m/Y') }}</td>
                </tr>
                @endif
                @if($document->date_publication)
                <tr>
                    <td class="label">Date de publication</td>
                    <td>{{ $document->date_publication->format('d/m/Y') }}</td>
                </tr>
                @endif
                @if($document->reference_nor)
                <tr>
                    <td class="label">Référence</td>
                    <td>{{ $document->reference_nor }}</td>
                </tr>
                @endif
                <tr>
                    <td class="label">Articles</td>
                    <td>{{ $document->articles->count() }}</td>
                </tr>
            </table>
        </div>

        <div class="cover-republic">
            <div class="republic">République du Congo</div>
            <div class="motto">Unité — Travail — Progrès</div>
        </div>
    </div>

    {{-- La couverture est positionnée en absolu : le flux normal démarre
         derrière elle sur la page 1 — ce saut force le sommaire en page 2. --}}
    <div class="page-break"></div>

    {{-- Sommaire (sections ordonnées et dédupliquées par le contrôleur) --}}
    @if($sections->isNotEmpty())
        <div class="toc">
            <h2 class="toc-title">Sommaire</h2>
            @foreach($sections as $section)
                <div class="toc-entry">
                    <span class="toc-kind">{{ $section['node']->type_unite }} {{ $section['node']->numero }}</span>
                    @if($section['node']->titre) — {{ $section['node']->titre }} @endif
                </div>
            @endforeach
            @if($orphans->isNotEmpty())
                <div class="toc-entry">
                    <span class="toc-kind">Dispositions complémentaires</span>
                </div>
            @endif
        </div>
        <div class="page-break"></div>
    @endif

    {{-- Corps du document --}}
    <div class="main-content" style="position: relative; z-index: 1;">
        @foreach($preambleArticles as $article)
            <div class="article-box">
                <span class="article-head">{{ $articleLabel($article->numero_article) }}</span>
                <div class="article-body">
                    {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                </div>
            </div>
        @endforeach

        @foreach($sections as $section)
            <div class="section-title">
                <span class="section-kind">{{ $section['node']->type_unite }} {{ $section['node']->numero }}</span>
                @if($section['node']->titre) &nbsp;{{ $section['node']->titre }} @endif
            </div>

            @foreach($section['articles'] as $article)
                <div class="article-box">
                    <span class="article-head">{{ $articleLabel($article->numero_article) }}</span>
                    <div class="article-body">
                        {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                    </div>
                </div>
            @endforeach
        @endforeach

        @if($orphans->isNotEmpty())
            <div class="section-title">
                <span class="section-kind">Dispositions complémentaires</span>
            </div>
            @foreach($orphans as $article)
                <div class="article-box">
                    <span class="article-head">{{ $articleLabel($article->numero_article) }}</span>
                    <div class="article-body">
                        {!! nl2br(e($article->activeVersion->contenu_texte ?? $article->latestVersion->contenu_texte ?? 'Contenu non disponible')) !!}
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
