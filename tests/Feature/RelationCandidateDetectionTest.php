<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Services\Curation\RelationCandidateDetector;

function creerArticleAvecTexte(LegalDocument $document, string $texte): Article
{
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => $texte]);

    return $article;
}

it('détecte une abrogation candidate quand une seule cible correspond et que la date concorde', function () {
    $cible = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-100 du 3 janvier 2025 portant organisation du ministère.',
        'date_signature' => '2025-01-03',
    ]);
    $source = LegalDocument::factory()->create(['document_role' => 'FLUX']);
    creerArticleAvecTexte($source, 'Le présent décret abroge le décret n° 2025-100 du 3 janvier 2025.');

    $resultat = (new RelationCandidateDetector)->detecter($source->fresh(['articles']));

    expect($resultat['candidats'])->toHaveCount(1)
        ->and($resultat['ambigus'])->toBe(0);

    $relation = $resultat['candidats'][0];
    expect($relation->relation_type)->toBe(DocumentRelation::TYPE_ABROGE)
        ->and($relation->status)->toBe(DocumentRelation::STATUS_CANDIDATE)
        ->and($relation->source)->toBe(DocumentRelation::SOURCE_HEURISTIC)
        ->and($relation->target_doc_id)->toBe($cible->id)
        ->and($relation->confidence)->toBe(0.9)
        ->and($relation->meta['extrait_source'])->toContain('2025-100');
});

it('baisse la confiance quand la date détectée ne concorde pas avec la cible', function () {
    LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 45-2020 du 12 mars 2020 fixant les modalités.',
        'date_signature' => '2019-01-01',
        'date_publication' => null,
    ]);
    $source = LegalDocument::factory()->create();
    creerArticleAvecTexte($source, 'Le présent arrêté modifie l\'arrêté n° 45-2020 du 12 mars 2020.');

    $resultat = (new RelationCandidateDetector)->detecter($source->fresh(['articles']));

    expect($resultat['candidats'])->toHaveCount(1)
        ->and($resultat['candidats'][0]->confidence)->toBe(0.6)
        ->and($resultat['candidats'][0]->relation_type)->toBe(DocumentRelation::TYPE_MODIFIE);
});

it('ne crée aucune relation et pose un signalement quand aucune cible ne correspond', function () {
    $source = LegalDocument::factory()->create();
    creerArticleAvecTexte($source, 'Le présent décret abroge le décret n° 9999-999 du 1 janvier 2000.');

    $resultat = (new RelationCandidateDetector)->detecter($source->fresh(['articles']));

    expect($resultat['candidats'])->toHaveCount(0)
        ->and($resultat['ambigus'])->toBe(1)
        ->and(DocumentRelation::count())->toBe(0);

    $flag = CurationFlag::first();
    expect($flag->type_probleme)->toBe('relation_ambigue')
        ->and($flag->source)->toBe(CurationFlag::SOURCE_HEURISTIC)
        ->and($flag->severity)->toBe(CurationFlag::SEVERITY_INFO)
        ->and($flag->suggestion['candidats'])->toBe([]);
});

it('pose un signalement, sans deviner, quand plusieurs cibles correspondent au même numéro', function () {
    LegalDocument::factory()->create(['titre_officiel' => 'Décret n° 12-2021 du 5 mai 2021 portant nomination.']);
    LegalDocument::factory()->create(['titre_officiel' => 'Décret n° 12-2021 bis du 6 mai 2021 portant rectification.']);
    $source = LegalDocument::factory()->create();
    creerArticleAvecTexte($source, 'Le présent texte modifie le décret n° 12-2021 du 5 mai 2021.');

    $resultat = (new RelationCandidateDetector)->detecter($source->fresh(['articles']));

    expect($resultat['candidats'])->toHaveCount(0)
        ->and(DocumentRelation::count())->toBe(0)
        ->and(CurationFlag::first()->suggestion['candidats'])->toHaveCount(2);
});

it('est idempotent : rejouer la détection ne duplique ni la relation ni le signalement', function () {
    LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-100 du 3 janvier 2025 portant organisation du ministère.',
        'date_signature' => '2025-01-03',
    ]);
    $sourceIntrouvable = LegalDocument::factory()->create();
    creerArticleAvecTexte($sourceIntrouvable, 'Abroge le décret n° 7777-000 du 1 février 2000.');

    $source = LegalDocument::factory()->create();
    creerArticleAvecTexte($source, 'Le présent décret abroge le décret n° 2025-100 du 3 janvier 2025.');

    $detecteur = new RelationCandidateDetector;
    $detecteur->detecter($sourceIntrouvable->fresh(['articles']));
    $detecteur->detecter($sourceIntrouvable->fresh(['articles']));
    $detecteur->detecter($source->fresh(['articles']));
    $detecteur->detecter($source->fresh(['articles']));

    expect(DocumentRelation::count())->toBe(1)
        ->and(CurationFlag::count())->toBe(1);
});
