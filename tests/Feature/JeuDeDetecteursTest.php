<?php

use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use App\Services\Curation\Detecteurs\D1NumeroDoublon;
use App\Services\Curation\Detecteurs\DetecteurContenu;
use App\Services\Curation\JeuDeDetecteurs;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creerArticleAvecNumero(LegalDocument $document, string $numero, string $contenu): void
{
    $article = $document->articles()->create(['numero_article' => $numero, 'ordre_affichage' => 1]);
    $article->versions()->create([
        'contenu_texte' => $contenu,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);
}

/** Détecteur factice qui lève systématiquement — prouve le chemin `incomplet`. */
class DetecteurEnEchecPourTest implements DetecteurContenu
{
    public function code(): string
    {
        return 'detecteur_factice_en_echec';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_WARNING;
    }

    public function detecter(LegalDocument $document): array
    {
        throw new RuntimeException('panne simulée');
    }
}

it('records resultat=ok and a zero count when nothing is detected', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Loi n° 1-2026 du 1 janvier 2026 portant test']);
    creerArticleAvecNumero($document, '1', 'Un contenu tout à fait normal, sans aucune anomalie détectable.');

    $run = (new JeuDeDetecteurs)->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_OK)
        ->and($run->version_jeu)->toBe(JeuDeDetecteurs::VERSION)
        ->and($run->resultats['d1_numero_doublon'])->toBe(0)
        ->and(CurationFlag::where('document_id', $document->id)->where('source', CurationFlag::SOURCE_CONFORMITE)->count())->toBe(0);
});

it('records resultat=echec and posts a conformite flag when an anomaly is found', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');

    $run = (new JeuDeDetecteurs)->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC)
        ->and($run->resultats['d1_numero_doublon'])->toBe(1);

    $flag = CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd1_numero_doublon')->first();
    expect($flag)->not->toBeNull()
        ->and($flag->source)->toBe(CurationFlag::SOURCE_CONFORMITE)
        ->and($flag->severity)->toBe(CurationFlag::SEVERITY_BLOCKING)
        ->and($flag->resolved)->toBeFalse();
});

it('is idempotent : a second run does not duplicate an already open flag', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');

    $jeu = new JeuDeDetecteurs;
    $jeu->controler($document);
    $jeu->controler($document);

    expect(CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd1_numero_doublon')->count())->toBe(1);
    // Deux runs distincts restent néanmoins consignés — historique, jamais écrasé.
    expect(DocumentControleRun::where('document_id', $document->id)->count())->toBe(2);
});

it('does not yet protect a resolved exception from being reflagged (gap tracked for the fingerprint phase)', function () {
    // Comportement TRANSITOIRE et documenté (docblock de JeuDeDetecteurs) :
    // l'idempotence de cette phase ne regarde que les signalements OUVERTS.
    // Un signalement déjà résolu (ex. exception « fidèle à la source »,
    // #27) n'empêche pas encore un nouveau signalement d'être posé — le
    // mécanisme d'empreinte de contenu qui doit l'empêcher arrive dans une
    // phase séparée (§ 3.5). Sans risque en production avant cette phase :
    // rien n'appelle encore `controler()` sur un calendrier (la commande
    // planifiée est une phase ultérieure, qui ne peut pas partir avant que
    // ce mécanisme existe). Ce test fixe le comportement actuel pour qu'un
    // changement futur le change consciemment, pas par accident.
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');
    $article = $document->articles()->first();

    CurationFlag::create([
        'document_id' => $document->id,
        'article_id' => $article->id,
        'source' => CurationFlag::SOURCE_CONFORMITE,
        'type_probleme' => 'd1_numero_doublon',
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'description' => 'déjà vu et résolu',
        'resolved' => true,
        'resolved_at' => now(),
    ]);

    $run = (new JeuDeDetecteurs)->controler($document);

    expect(CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd1_numero_doublon')->count())->toBe(2)
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC);
});

it('marks resultat=incomplet and logs a false count when a detector throws, without blocking the others', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Loi n° 1-2026 du 1 janvier 2026 portant test']);
    creerArticleAvecNumero($document, '1', 'Un contenu tout à fait normal.');

    $jeu = new JeuDeDetecteurs([new DetecteurEnEchecPourTest, new D1NumeroDoublon]);
    $run = $jeu->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_INCOMPLET)
        ->and($run->resultats['detecteur_factice_en_echec'])->toBeFalse()
        ->and($run->resultats['d1_numero_doublon'])->toBe(0);
});
