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

    /** Détecteur factice qui lève systématiquement — prouve le chemin `incomplet`. */
    $detecteurEnEchec = new class implements DetecteurContenu
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
    };

    $jeu = new JeuDeDetecteurs([$detecteurEnEchec, new D1NumeroDoublon]);
    $run = $jeu->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_INCOMPLET)
        ->and($run->resultats['detecteur_factice_en_echec'])->toBeFalse()
        ->and($run->resultats['d1_numero_doublon'])->toBe(0);
});

/**
 * Détecteur factice dont les candidats se règlent à la main : prouve les
 * règles de fermeture (identité = document, détecteur, article) sans dépendre
 * de la condition d'un vrai détecteur.
 *
 * @param  array<int, string|null>  $articlesEnDefaut  `null` = défaut du document
 */
function detecteurFactice(string $code, array $articlesEnDefaut): DetecteurContenu
{
    return new class($code, $articlesEnDefaut) implements DetecteurContenu
    {
        public function __construct(private string $code, private array $articlesEnDefaut) {}

        public function code(): string
        {
            return $this->code;
        }

        public function severity(): string
        {
            return CurationFlag::SEVERITY_BLOCKING;
        }

        public function detecter(LegalDocument $document): array
        {
            return array_map(function (?string $articleId) {
                $candidat = ['description' => 'défaut factice', 'empreinte_source' => 'source '.($articleId ?? 'document')];
                if ($articleId !== null) {
                    $candidat['article_id'] = $articleId;
                }

                return $candidat;
            }, $this->articlesEnDefaut);
        }
    };
}

function signalementOuvert(LegalDocument $document, ?string $articleId, string $source, string $type): CurationFlag
{
    return CurationFlag::create([
        'document_id' => $document->id,
        'article_id' => $articleId,
        'source' => $source,
        'type_probleme' => $type,
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'description' => 'posé avant que le texte soit réécrit',
        'anchor' => ['empreinte' => hash('sha256', 'ancienne source')],
        'resolved' => false,
    ]);
}

it('closes a conformite flag when its defect has disappeared, and stays idempotent', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');
    $article = $document->articles()->first();

    $jeu = new JeuDeDetecteurs;
    expect($jeu->controler($document)->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC);

    // L'éditeur corrige le numéro : D1 et D2 ne retrouvent plus rien.
    $article->update(['numero_article' => '12']);

    $run = $jeu->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_OK);
    $flags = CurationFlag::where('document_id', $document->id)->get();
    expect($flags)->toHaveCount(2); // d1 + d2, jamais supprimés
    foreach ($flags as $flag) {
        expect($flag->resolved)->toBeTrue()
            ->and($flag->resolved_at)->not->toBeNull()
            ->and($flag->resolved_by)->toBeNull()
            ->and($flag->anchor['fermeture'])->toBe(JeuDeDetecteurs::FERMETURE_DEFAUT_DISPARU)
            ->and($flag->anchor['empreinte'])->not->toBeNull();
    }

    $ferméA = $flags->first()->resolved_at;
    $this->travel(5)->minutes();
    $jeu->controler($document);

    expect(CurationFlag::where('document_id', $document->id)->count())->toBe(2)
        ->and(CurationFlag::find($flags->first()->id)->resolved_at->equalTo($ferméA))->toBeTrue();
});

it('keeps a flag open while its defect persists, even if the content moved', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');

    $jeu = new JeuDeDetecteurs;
    $jeu->controler($document);

    $document->articles()->first()->activeVersion->update(['contenu_texte' => 'Un texte retouché, toujours suffisamment long.']);
    $run = $jeu->controler($document);

    $flags = CurationFlag::where('document_id', $document->id)->where('type_probleme', 'd1_numero_doublon')->get();
    expect($flags)->toHaveCount(1)
        ->and($flags->first()->resolved)->toBeFalse()
        ->and($flags->first()->anchor)->not->toHaveKey('fermeture')
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC);
});

it('writes nothing in dry-run and reports the flags it would close', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');
    $article = $document->articles()->first();

    $jeu = new JeuDeDetecteurs;
    $jeu->controler($document);
    $article->update(['numero_article' => '12']);

    $run = $jeu->controler($document, dryRun: true);

    expect($run->exists)->toBeFalse()
        ->and(DocumentControleRun::where('document_id', $document->id)->count())->toBe(1)
        ->and(CurationFlag::where('document_id', $document->id)->where('resolved', false)->count())->toBe(2)
        ->and($jeu->signalementsPerimes($document))->toHaveCount(2)
        ->and(CurationFlag::where('document_id', $document->id)->where('resolved', false)->count())->toBe(2);
});

it('never touches the flags of other sources, whatever their type', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12', 'Un contenu tout à fait normal, sans aucune anomalie.');
    $article = $document->articles()->first();

    $autres = collect([
        CurationFlag::SOURCE_HUMAN,
        CurationFlag::SOURCE_HEURISTIC,
        CurationFlag::SOURCE_STRUCTURAL,
        CurationFlag::SOURCE_REPORT,
        CurationFlag::SOURCE_LLM,
    ])->map(fn (string $source) => signalementOuvert($document, $article->id, $source, 'd1_numero_doublon'));
    $conforme = signalementOuvert($document, $article->id, CurationFlag::SOURCE_CONFORMITE, 'd1_numero_doublon');

    (new JeuDeDetecteurs)->controler($document);

    expect($conforme->fresh()->resolved)->toBeTrue();
    foreach ($autres as $flag) {
        expect($flag->fresh()->resolved)->toBeFalse();
    }
});

it('closes per identity : only the article that no longer has the defect', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '1', 'Premier article.');
    creerArticleAvecNumero($document, '2', 'Second article.');
    [$a, $b] = $document->articles()->get()->all();

    $sur_a = signalementOuvert($document, $a->id, CurationFlag::SOURCE_CONFORMITE, 'detecteur_factice');
    $sur_b = signalementOuvert($document, $b->id, CurationFlag::SOURCE_CONFORMITE, 'detecteur_factice');
    $sur_document = signalementOuvert($document, null, CurationFlag::SOURCE_CONFORMITE, 'detecteur_factice');
    $autre_type = signalementOuvert($document, $b->id, CurationFlag::SOURCE_CONFORMITE, 'detecteur_non_execute');

    (new JeuDeDetecteurs([detecteurFactice('detecteur_factice', [$a->id])]))->controler($document);

    expect($sur_a->fresh()->resolved)->toBeFalse()
        ->and($sur_b->fresh()->resolved)->toBeTrue()
        ->and($sur_document->fresh()->resolved)->toBeTrue()
        ->and($autre_type->fresh()->resolved)->toBeFalse();
});

it('closes nothing for a detector that failed', function () {
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '1', 'Un article.');
    $article = $document->articles()->first();
    $ouvert = signalementOuvert($document, $article->id, CurationFlag::SOURCE_CONFORMITE, 'd1_numero_doublon');

    $enEchec = new class implements DetecteurContenu
    {
        public function code(): string
        {
            return 'd1_numero_doublon';
        }

        public function severity(): string
        {
            return CurationFlag::SEVERITY_BLOCKING;
        }

        public function detecter(LegalDocument $document): array
        {
            throw new RuntimeException('panne simulée');
        }
    };

    $run = (new JeuDeDetecteurs([$enEchec]))->controler($document);

    expect($ouvert->fresh()->resolved)->toBeFalse()
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_INCOMPLET)
        ->and((new JeuDeDetecteurs([$enEchec]))->signalementsPerimes($document))->toBeEmpty();
});

it('flags a defect again when it comes back after the control closed its flag', function () {
    // Une fermeture « défaut disparu » n'est pas une exception humaine : si le
    // numéro fautif revient avec le même texte, le défaut doit ressortir.
    $document = LegalDocument::factory()->create();
    creerArticleAvecNumero($document, '12-doublon', 'Contenu quelconque, suffisamment long.');
    $article = $document->articles()->first();

    $jeu = new JeuDeDetecteurs([new D1NumeroDoublon]);
    $jeu->controler($document);
    $article->update(['numero_article' => '12']);
    $jeu->controler($document);
    $article->update(['numero_article' => '12-doublon']);
    $run = $jeu->controler($document);

    $flags = CurationFlag::where('document_id', $document->id)->get();
    expect($flags)->toHaveCount(2)
        ->and($flags->where('resolved', false))->toHaveCount(1)
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC);
});

it('binds a resolved d1/d2 exception to the article number, not to the text alone', function () {
    $document = LegalDocument::factory()->create();
    $contenu = 'Contenu quelconque, suffisamment long.';
    creerArticleAvecNumero($document, '12-doublon', $contenu);
    $article = $document->articles()->first();

    $jeu = new JeuDeDetecteurs([new D1NumeroDoublon]);
    $jeu->controler($document);
    // Un éditeur résout le signalement d'un clic : exception pour CE numéro.
    CurationFlag::where('document_id', $document->id)->update(['resolved' => true, 'resolved_at' => now()]);

    expect($jeu->controler($document)->resultat)->toBe(DocumentControleRun::RESULTAT_OK)
        ->and(CurationFlag::where('document_id', $document->id)->count())->toBe(1);

    // Le numéro change mais reste fautif, le texte ne bouge pas : ce n'est
    // plus le numéro examiné, l'exception ne couvre plus rien.
    $article->update(['numero_article' => '13-doublon']);
    $run = $jeu->controler($document);

    $flags = CurationFlag::where('document_id', $document->id)->orderBy('created_at')->orderBy('id')->get();
    expect($flags)->toHaveCount(2)
        ->and($flags->last()->resolved)->toBeFalse()
        ->and($run->resultat)->toBe(DocumentControleRun::RESULTAT_ECHEC);
});

it('honors a legacy content-only d1/d2 exception once, then rebinds it to the number', function () {
    // 81 exceptions humaines existaient en production avec l'empreinte « contenu
    // seul » : le changement d'empreinte ne doit pas les rouvrir en bloc.
    $document = LegalDocument::factory()->create();
    $contenu = 'Contenu quelconque, suffisamment long.';
    creerArticleAvecNumero($document, '12-doublon', $contenu);
    $article = $document->articles()->first();

    $herite = CurationFlag::create([
        'document_id' => $document->id,
        'article_id' => $article->id,
        'source' => CurationFlag::SOURCE_CONFORMITE,
        'type_probleme' => 'd1_numero_doublon',
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'description' => 'fidèle à la source (#27)',
        'anchor' => ['empreinte' => hash('sha256', $contenu)],
        'resolved' => true,
        'resolved_at' => now(),
    ]);

    $jeu = new JeuDeDetecteurs([new D1NumeroDoublon]);
    $run = $jeu->controler($document);

    expect($run->resultat)->toBe(DocumentControleRun::RESULTAT_OK)
        ->and(CurationFlag::where('document_id', $document->id)->count())->toBe(1)
        ->and($herite->fresh()->resolved)->toBeTrue()
        ->and($herite->fresh()->anchor['empreinte'])->toBe(hash('sha256', "12-doublon\n".$contenu));

    // Réestampillée : un changement de numéro la rend désormais caduque.
    $article->update(['numero_article' => '13-doublon']);
    $jeu->controler($document);

    expect(CurationFlag::where('document_id', $document->id)->count())->toBe(2);
});

it('does not rewrite a legacy exception in dry-run', function () {
    $document = LegalDocument::factory()->create();
    $contenu = 'Contenu quelconque, suffisamment long.';
    creerArticleAvecNumero($document, '12-doublon', $contenu);
    $article = $document->articles()->first();

    $herite = CurationFlag::create([
        'document_id' => $document->id,
        'article_id' => $article->id,
        'source' => CurationFlag::SOURCE_CONFORMITE,
        'type_probleme' => 'd1_numero_doublon',
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'description' => 'fidèle à la source (#27)',
        'anchor' => ['empreinte' => hash('sha256', $contenu)],
        'resolved' => true,
        'resolved_at' => now(),
    ]);

    (new JeuDeDetecteurs([new D1NumeroDoublon]))->controler($document, dryRun: true);

    expect($herite->fresh()->anchor['empreinte'])->toBe(hash('sha256', $contenu));
});
