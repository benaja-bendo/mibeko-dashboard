<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Le tri « prêt à publier / à instruire » qui précède toute vague de publication.
 * Ce que les garde-fous de l'API ne voient pas — un intitulé qui trahit un
 * document né d'un mauvais découpage — doit être arrêté ici.
 */
uses(RefreshDatabase::class);

function documentAvecArticle(array $attributs = []): LegalDocument
{
    $document = LegalDocument::factory()->create($attributs + ['curation_status' => 'draft']);
    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

it('déclare prêt un brouillon à l\'intitulé canonique, articles présents et sans anomalie', function () {
    documentAvecArticle(['titre_officiel' => 'Loi n° 9-2004 du 26 mars 2004 portant code du domaine de l\'État']);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toHaveCount(1);
});

it('écarte un fragment de phrase promu en acte par le découpage', function () {
    documentAvecArticle(['titre_officiel' => 'arrêté pourra faire l\'objet d\'une suspension ou d\'un']);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';
    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toBe([]);

    $instruire = json_decode((string) file_get_contents($rapport), true);
    expect($instruire)->toHaveCount(1)
        ->and($instruire[0]['defauts'])->toContain('commence en minuscule (fragment probable)');
});

it('écarte un intitulé partagé par plusieurs documents', function () {
    // Deux actes distincts ne portent pas le même intitulé exact : c'est la
    // signature d'un découpage qui a répété une formule de fin d'acte.
    documentAvecArticle(['titre_officiel' => 'DECISION DU 9 FEVRIER 1959']);
    documentAvecArticle(['titre_officiel' => 'DECISION DU 9 FEVRIER 1959']);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $instruire = json_decode((string) file_get_contents($rapport), true);
    expect($instruire)->toHaveCount(2)
        ->and($instruire[0]['defauts'])->toContain('intitulé partagé par 2 documents');
});

it('écarte un intitulé où l\'OCR a laissé du LaTeX', function () {
    documentAvecArticle(['titre_officiel' => 'LOI  $\mathbf{N}^{\circ}$  076-84 du 7 décembre 1984, portant ratification']);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['defauts'])
        ->toContain('LaTeX non converti');
});

it('écarte une phrase qui commence par un mot-type mais ne porte ni numéro ni date', function () {
    // Cas réel publié par erreur le 03/08/2026 avant ce garde-fou : le titre
    // matche « commence par Loi », mais c'est un fragment de discours.
    documentAvecArticle(['titre_officiel' => 'Loi-Cadre, cède la place à une seule tête, choisie par vous.']);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['defauts'])
        ->toContain('aucun numéro ni date (probable phrase, pas un intitulé)');
});

it('écarte un item d\'énumération coupé qui se termine sur une référence incomplète', function () {
    // Cas réel publié par erreur le 03/08/2026 : extrait d'un compte-rendu qui
    // énumère plusieurs textes adoptés, coupé avant le numéro du décret.
    documentAvecArticle(['titre_officiel' => 'Loi sur la concurrence ; et (c) adopté le Décret n°']);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toBe([]);
});

it('accepte un intitulé canonique même quand le numéro suit directement le type', function () {
    documentAvecArticle(['titre_officiel' => 'Loi n° 28-2016 du 12 octobre 2016 portant code forestier']);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toHaveCount(1);
});

it('classe comme bloqué, et non comme prêt, un document sans article', function () {
    LegalDocument::factory()->create([
        'titre_officiel' => 'Loi n° 49-59 du 17 novembre 1959 portant code des impôts directs',
        'curation_status' => 'draft',
    ]);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toBe([]);
});

it('classe comme bloqué un document porteur d\'une anomalie bloquante non résolue', function () {
    $document = documentAvecArticle([
        'titre_officiel' => 'Décret n° 2023-679 du 28 juin 2023 fixant les attributions du ministre',
    ]);

    CurationFlag::create([
        'document_id' => $document->id,
        'source' => 'heuristic',
        'type_probleme' => 'article_manquant',
        'severity' => 'blocking',
        'description' => 'Numéros d\'article absents (13-40).',
        'resolved' => false,
    ]);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toBe([]);
});

it('ne retient que les types demandés', function () {
    $loi = DocumentType::factory()->create(['code' => 'LOI']);
    $arrete = DocumentType::factory()->create(['code' => 'ARR']);

    documentAvecArticle(['titre_officiel' => 'Loi n° 28-2016 du 12 octobre 2016 portant code forestier', 'type_code' => $loi->code]);
    documentAvecArticle(['titre_officiel' => 'Arrêté n° 3862 du 9 septembre 2025 fixant les modalités', 'type_code' => $arrete->code]);

    $liste = tempnam(sys_get_temp_dir(), 'prets_').'.json';

    $this->artisan('mibeko:auditer-publiables', [
        '--connection' => 'pgsql',
        '--types' => 'LOI',
        '--liste-prets' => $liste,
    ])->assertSuccessful();

    $prets = json_decode((string) file_get_contents($liste), true);
    expect($prets)->toHaveCount(1)
        ->and($prets[0]['titre'])->toStartWith('Loi n° 28-2016');
});
