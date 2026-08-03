<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cas modelé sur le cluster minier réel découvert le 03/08/2026 : un
 * découpage MinerU coupe systématiquement en plein article 7 d'une série
 * d'arrêtés d'un même JO, la seconde moitié devenant un « document » à part
 * partageant un intitulé identique (la clause coupée).
 */
uses(RefreshDatabase::class);

function arreteTete(string $jo, int $numero, string $article7Tronque): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'titre_officiel' => "Arrêté n° {$numero} du 18 juin 2025 portant attribution",
        'official_journal_id' => $jo,
        'curation_status' => 'published',
    ]);

    foreach (range(1, 6) as $n) {
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => (string) $n, 'ordre_affichage' => $n]);
        ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => "Contenu de l'article {$n}."]);
    }

    $article7 = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '7', 'ordre_affichage' => 7]);
    ArticleVersion::factory()->create(['article_id' => $article7->id, 'contenu_texte' => $article7Tronque]);

    return $document;
}

function fragmentSuspension(string $jo, string $documentKey, string $suiteArticle7): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        'official_journal_id' => $jo,
        'curation_status' => 'draft',
        'document_key' => $documentKey,
    ]);

    $preambule = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'PREAMBULE', 'ordre_affichage' => 0]);
    ArticleVersion::factory()->create(['article_id' => $preambule->id, 'contenu_texte' => $suiteArticle7]);

    foreach ([8, 9, 10] as $i => $n) {
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => (string) $n, 'ordre_affichage' => $i + 1]);
        ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => "Article {$n}."]);
    }

    $signature = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'SIGNATURE', 'ordre_affichage' => 4]);
    ArticleVersion::factory()->create(['article_id' => $signature->id, 'contenu_texte' => 'Fait à Brazzaville, le 18 juin 2025.']);

    return $document;
}

it('apparie une tête et un fragment sur un JO à une seule paire', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($plan), true);
    expect($resultat)->toHaveCount(1)
        ->and($resultat[0]['tete_id'])->toBe($tete->id)
        ->and($resultat[0]['tete_statut'])->toBe('published');
});

it('apparie plusieurs paires dans l\'ordre du numéro d\'acte et du suffixe _acte_N', function () {
    $jo = OfficialJournal::factory()->create()->id;

    $tete1 = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    $tete2 = arreteTete($jo, 1551, 'Conformément aux articles 91 et 92 de la');
    $tete3 = arreteTete($jo, 1552, 'Conformément aux articles 91 et 92 de la');

    $frag1 = fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution (base).');
    $frag2 = fragmentSuspension($jo, 'flux:frag-jo28_acte_1', 'retrait en cas de non-exécution (acte_1).');
    $frag3 = fragmentSuspension($jo, 'flux:frag-jo28_acte_2', 'retrait en cas de non-exécution (acte_2).');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = collect(json_decode((string) file_get_contents($plan), true))->keyBy('tete_id');

    expect($resultat)->toHaveCount(3)
        ->and($resultat[$tete1->id]['fragment_id'])->toBe($frag1->id)
        ->and($resultat[$tete2->id]['fragment_id'])->toBe($frag2->id)
        ->and($resultat[$tete3->id]['fragment_id'])->toBe($frag3->id);
});

it('ignore un arrêté non tronqué intercalé sans décaler l\'appariement des vrais membres suivants', function () {
    // Cas réel régressé avant ce garde-fou : 3 arrêtés non tronqués intercalés
    // (n°1556-1558) décalaient tous les index suivants, poussant 3 vrais
    // membres du cluster (n°1562-1564) hors de la fenêtre de comparaison.
    $jo = OfficialJournal::factory()->create()->id;

    $tete1550 = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    // Intercalé : un arrêté complet (dernier article correctement ponctué),
    // sans rapport avec le cluster, mais portant un numéro entre les deux.
    $interloper = LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 1556 du 18 juin 2025 portant nomination.',
        'official_journal_id' => $jo,
        'curation_status' => 'published',
    ]);
    $articleInterloper = Article::factory()->create(['document_id' => $interloper->id, 'numero_article' => '3']);
    ArticleVersion::factory()->create(['article_id' => $articleInterloper->id, 'contenu_texte' => 'Ceci est un article complet et sans rapport.']);

    $tete1560 = arreteTete($jo, 1560, 'Conformément aux articles 91 et 92 de la');

    $frag1 = fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution (pour 1550).');
    $frag2 = fragmentSuspension($jo, 'flux:frag-jo28_acte_1', 'retrait en cas de non-exécution (pour 1560).');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = collect(json_decode((string) file_get_contents($plan), true))->keyBy('tete_id');

    expect($resultat)->toHaveCount(2)
        ->and($resultat[$tete1550->id]['fragment_id'])->toBe($frag1->id)
        ->and($resultat[$tete1560->id]['fragment_id'])->toBe($frag2->id);
});

it('ignore un candidat sans aucun article numérique sans décaler l\'appariement, comme un candidat non tronqué', function () {
    $jo = OfficialJournal::factory()->create()->id;

    $tete1550 = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    // Intercalé : un document sans AUCUN article numéroté (ex. structure PREAMBULE/SIGNATURE seule).
    LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 1556 du 18 juin 2025 portant nomination.',
        'official_journal_id' => $jo,
        'curation_status' => 'published',
    ]);
    $tete1560 = arreteTete($jo, 1560, 'Conformément aux articles 91 et 92 de la');

    $frag1 = fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution (pour 1550).');
    $frag2 = fragmentSuspension($jo, 'flux:frag-jo28_acte_1', 'retrait en cas de non-exécution (pour 1560).');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = collect(json_decode((string) file_get_contents($plan), true))->keyBy('tete_id');

    expect($resultat)->toHaveCount(2)
        ->and($resultat[$tete1550->id]['fragment_id'])->toBe($frag1->id)
        ->and($resultat[$tete1560->id]['fragment_id'])->toBe($frag2->id);
});

it('écarte une paire dont le dernier article de la tête se termine par une ponctuation forte', function () {
    $jo = OfficialJournal::factory()->create()->id;
    arreteTete($jo, 1550, 'Ceci est un article complet.');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('ecarte')
        ->and($resultat['raison'])->toContain('ponctuation forte');
});

it('écarte une paire dont le premier morceau du fragment commence par une majuscule', function () {
    $jo = OfficialJournal::factory()->create()->id;
    arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'Retrait en cas de non-exécution, phrase indépendante.');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('ecarte')
        ->and($resultat['raison'])->toContain('minuscule');
});

it('exécute la fusion : complète l\'article, crée les articles suivants, retire le fragment', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    $fragment = fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    $this->artisan('mibeko:fusionner-fragments', [
        '--plan' => $plan,
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertSuccessful();

    // Article 7 complété.
    $article7 = Article::where('document_id', $tete->id)->where('numero_article', '7')->first();
    expect($article7->versions->first()->contenu_texte)
        ->toBe("Conformément aux articles 91 et 92 de la\nretrait en cas de non-exécution.");

    // Articles 8, 9, 10, SIGNATURE rapatriés sous la tête.
    $nouveauxNumeros = Article::where('document_id', $tete->id)->whereNotIn('numero_article', ['1', '2', '3', '4', '5', '6', '7'])
        ->pluck('numero_article')->sort()->values()->all();
    expect($nouveauxNumeros)->toBe(['8', '9', '10', 'SIGNATURE']);

    // Le fragment est retiré (soft-delete), y compris ses propres articles.
    expect(LegalDocument::withTrashed()->find($fragment->id)->trashed())->toBeTrue();
    expect(Article::withTrashed()->where('document_id', $fragment->id)->get()->every->trashed())->toBeTrue();

    // La tête reste PUBLIÉE : ce correctif ne dépublie jamais.
    expect($tete->fresh()->curation_status)->toBe('published');
});

it('écrit le fichier de retour arrière avant toute écriture', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';
    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un', '--plan' => $plan,
    ])->assertSuccessful();

    $retour = tempnam(sys_get_temp_dir(), 'retour_').'.json';
    $this->artisan('mibeko:fusionner-fragments', [
        '--plan' => $plan, '--connection' => 'pgsql', '--execute' => true, '--revert-file' => $retour,
    ])->assertSuccessful();

    $donnees = json_decode((string) file_get_contents($retour), true);
    expect($donnees[0]['tete_id'])->toBe($tete->id)
        ->and($donnees[0]['contenu_original'])->toBe('Conformément aux articles 91 et 92 de la');
});

it('refuse --execute sur la connexion de lecture seule', function () {
    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';
    file_put_contents($plan, json_encode([['tete_id' => 'x', 'fragment_id' => 'y']]));

    $this->artisan('mibeko:fusionner-fragments', [
        '--plan' => $plan, '--connection' => 'pgsql_prod_ro', '--execute' => true,
    ])->assertFailed();
});
