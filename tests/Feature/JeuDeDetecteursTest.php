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

it('does not reflag a resolved exception whose content fingerprint is unchanged', function () {
    // Cas fondateur du mécanisme (#27) : une coquille du JO lui-même,
    // examinée et classée « fidèle à la source » par un humain — elle ne
    // doit jamais revenir tant que le texte à cet ancrage n'a pas bougé,
    // même après des dizaines de passages du contrôle planifié. Un numéro
    // « -doublon » déclenche aussi D2 (aucun suffixe « doublon » n'est dans
    // sa liste blanche) : les DEUX exceptions sont pré-résolues, comme un
    // humain qui revoit ce document le ferait pour les deux à la fois.
    $document = LegalDocument::factory()->create();
    $contenu = 'Contenu quelconque, suffisamment long.';
    creerArticleAvecNumero($document, '12-doublon', $contenu);
    $article = $document->articles()->first();

    foreach (['d1_numero_doublon', 'd2_numero_hors_liste_blanche'] as $typeProbleme) {
        CurationFlag::create([
            'document_id' => $document->id,
            'article_id' => $article->id,
            'source' => CurationFlag::SOURCE_CONFORMITE,
            'type_probleme' => $typeProbleme,
            'severity' => CurationFlag::SEVERITY_BLOCKING,
            'description' => 'déjà vu, fidèle à la source (#27)',
            'anchor' => ['empreinte' => hash('sha256', $contenu)],
            'resolved' => true,
            'resolved_at' => now(),
        ]);
    }

    $run = (new JeuDeDetecteurs)->controler($document);

    expect(CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd1_numero_doublon')->count())->toBe(1)
        ->and(CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd2_numero_hors_liste_blanche')->count())->toBe(1)
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_OK);
});

it('reflags when the content at the anchor changed since the resolved exception', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');
    $article = $document->articles()->first();

    CurationFlag::create([
        'document_id' => $document->id,
        'article_id' => $article->id,
        'source' => CurationFlag::SOURCE_CONFORMITE,
        'type_probleme' => 'd1_numero_doublon',
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'description' => 'empreinte d\'un contenu antérieur, depuis modifié',
        'anchor' => ['empreinte' => hash('sha256', 'un contenu complètement différent')],
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
