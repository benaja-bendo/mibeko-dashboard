<?php

use App\Ai\CorpusVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

/**
 * Cas modelé sur le cluster minier réel découvert le 03/08/2026 : un
 * découpage MinerU coupe systématiquement en plein article 7 d'une série
 * d'arrêtés d'un même JO, la seconde moitié devenant un « document » à part
 * partageant un intitulé identique (la clause coupée).
 */
uses(RefreshDatabase::class);

function arreteTete(string $jo, int $numero, string $article7Tronque, ?string $documentKey = null): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'titre_officiel' => "Arrêté n° {$numero} du 18 juin 2025 portant attribution",
        'official_journal_id' => $jo,
        'curation_status' => 'published',
        'document_key' => $documentKey,
    ]);

    foreach (range(1, 6) as $n) {
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => (string) $n, 'ordre_affichage' => $n]);
        ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => "Contenu de l'article {$n}."]);
    }

    $article7 = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '7', 'ordre_affichage' => 7]);
    ArticleVersion::factory()->create(['article_id' => $article7->id, 'contenu_texte' => $article7Tronque]);

    return $document;
}

function fragmentSuspension(string $jo, string $documentKey, string $suiteArticle7, ?string $titre = null): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'titre_officiel' => $titre ?? 'arrêté pourra faire l’objet d’une suspension ou d’un',
        'official_journal_id' => $jo,
        'curation_status' => 'draft',
        'document_key' => $documentKey,
    ]);

    $preambule = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'PREAMBULE', 'ordre_affichage' => 0]);
    ArticleVersion::factory()->create(['article_id' => $preambule->id, 'contenu_texte' => $suiteArticle7]);

    foreach ([8, 9, 10] as $i => $n) {
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => (string) $n, 'ordre_affichage' => $i + 1]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Article {$n}.",
            'source_locator' => ['page' => 42],
        ]);
    }

    $signature = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'SIGNATURE', 'ordre_affichage' => 4]);
    ArticleVersion::factory()->create(['article_id' => $signature->id, 'contenu_texte' => 'Fait à Brazzaville, le 18 juin 2025.']);

    return $document;
}

/** Article d'un document repéré par son numéro, versions comprises. */
function articleNumero(LegalDocument $document, string $numero): Article
{
    return Article::where('document_id', $document->id)->where('numero_article', $numero)->firstOrFail();
}

/** Diagnostic + exécution en une passe, sur la connexion de test. */
function fusionner(array $optionsDiagnostic = [], array $optionsExecution = []): string
{
    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    test()->artisan('mibeko:fusionner-fragments', array_merge([
        '--connection' => 'pgsql',
        '--plan' => $plan,
    ], $optionsDiagnostic))->assertSuccessful();

    test()->artisan('mibeko:fusionner-fragments', array_merge([
        '--connection' => 'pgsql',
        '--plan' => $plan,
        '--execute' => true,
    ], $optionsExecution))->assertSuccessful();

    return $plan;
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

it('écarte une paire dont un numéro rapatrié entre en collision avec un article de la tête', function () {
    // `uq_articles_document_numero` est un index unique partiel : sans ce
    // contrôle, l'INSERT échouerait et annulerait la transaction, donc TOUT le
    // lot — y compris les paires saines déjà fusionnées.
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');

    // La tête a déjà gardé sa formule finale : le SIGNATURE du fragment ne peut
    // pas la rejoindre. Une collision sur un numéro non maximal, invisible pour
    // les contrôles de troncature et de numérotation.
    $doublon = Article::factory()->create(['document_id' => $tete->id, 'numero_article' => 'SIGNATURE', 'ordre_affichage' => 20]);
    ArticleVersion::factory()->create(['article_id' => $doublon->id, 'contenu_texte' => 'Fait à Brazzaville, le 18 juin 2025.']);

    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('ecarte')
        ->and($resultat['raison'])->toContain('numéro(s) SIGNATURE');
});

it('exécute la fusion : complète l\'article, crée les articles suivants, retire le fragment', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    $fragment = fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    // Article 7 complété : c'est la version QUI FAIT FOI qui porte le texte recollé.
    $article7 = articleNumero($tete, '7');
    expect($article7->activeVersion()->first()->contenu_texte)
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

it('ouvre une nouvelle version de l\'article corrigé et ferme la précédente au lieu de la réécrire', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $article7 = articleNumero($tete, '7');
    $versionInitialeId = $article7->versions()->firstOrFail()->id;

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    expect($article7->versions()->count())->toBe(2);

    // L'ancienne garde le texte tronqué et n'est plus ouverte : l'historique
    // du texte publié reste consultable, la correction est datée.
    $ancienne = ArticleVersion::findOrFail($versionInitialeId);
    expect($ancienne->contenu_texte)->toBe('Conformément aux articles 91 et 92 de la')
        ->and(DB::selectOne('select upper_inf(validity_period) as ouverte from article_versions where id = ?', [$ancienne->id])->ouverte)
        ->toBeFalse();

    $active = $article7->activeVersion()->firstOrFail();
    expect($active->id)->not->toBe($versionInitialeId)
        ->and($active->contenu_texte)->toContain('retrait en cas de non-exécution.');
});

it('remet à NULL l\'embedding d\'une version déjà ouverte aujourd\'hui, qui est réécrite sur place', function () {
    // La branche « version ouverte aujourd\'hui » ne crée PAS de version neuve :
    // elle réécrit celle du jour (fermer une période commencée le jour même
    // produirait un daterange vide, rejeté par chk_article_versions_validity_not_empty).
    // C\'est le SEUL chemin où `'embedding' => null` est réellement porteur —
    // sur une version créée, la colonne est nulle par défaut, donc un test qui
    // ne passe que par là resterait vert même sans le correctif.
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $article7 = articleNumero($tete, '7');
    $version = $article7->versions()->firstOrFail();
    $version->validity_period = '['.now()->toDateString().',)';
    $version->embedding = array_fill(0, 1024, 0.1);
    $version->saveQuietly();
    $idAvant = $version->id;

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    $active = $article7->activeVersion()->firstOrFail();
    expect($article7->versions()->count())->toBe(1)
        ->and($active->id)->toBe($idAvant)
        ->and($active->contenu_texte)->toContain('retrait en cas de non-exécution.')
        ->and($active->embedding)->toBeNull();
});

it('laisse l\'embedding de la version corrigée à NULL pour que le cron RAG la rattrape', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    // Vecteur du texte TRONQUÉ, tel que le cron l'aurait déjà calculé en prod.
    $article7 = articleNumero($tete, '7');
    $versionTronquee = $article7->versions()->firstOrFail();
    $versionTronquee->embedding = array_fill(0, 1024, 0.1);
    $versionTronquee->saveQuietly();

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    expect($article7->activeVersion()->firstOrFail()->embedding)->toBeNull();

    // Les articles rapatriés aussi : sinon ils resteraient hors du RAG.
    $rapatrie = articleNumero($tete, '8');
    expect($rapatrie->activeVersion()->firstOrFail()->embedding)->toBeNull();

    // `mibeko:process-rag` ne sélectionne que les versions sans embedding : la
    // correction doit être dans son périmètre.
    expect(ArticleVersion::whereNull('embedding')->whereIn('article_id', [$article7->id, $rapatrie->id])->count())->toBe(2);
});

it('invalide le cache de réponses de l\'assistant après la fusion', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';
    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un',
        '--plan' => $plan,
    ])->assertSuccessful();

    // Jeton figé APRÈS le diagnostic : seule l'écriture doit le faire bouger.
    $avant = CorpusVersion::current();

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--plan' => $plan, '--execute' => true,
    ])->assertSuccessful();

    expect(CorpusVersion::current())->not->toBe($avant);
    expect($tete->fresh()->curation_status)->toBe('published');
});

it('rafraîchit updated_at de l\'article corrigé et de son document pour la synchro mobile', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $article7 = articleNumero($tete, '7');

    // Antidaté : sans rafraîchissement, SyncController pousserait aux mobiles
    // les articles rapatriés mais pas l'article corrigé — document incohérent
    // hors ligne.
    $vieux = now()->subYear();
    DB::table('articles')->where('id', $article7->id)->update(['updated_at' => $vieux]);
    DB::table('legal_documents')->where('id', $tete->id)->update(['updated_at' => $vieux]);

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    expect($article7->fresh()->updated_at->isToday())->toBeTrue()
        ->and($tete->fresh()->updated_at->isToday())->toBeTrue();
});

it('trace la correction dans l\'audit owen-it', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $article7 = articleNumero($tete, '7');
    Audit::query()->delete();

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    $versionCorrigee = $article7->activeVersion()->firstOrFail();

    expect(Audit::where('auditable_type', ArticleVersion::class)->where('auditable_id', $versionCorrigee->id)->where('event', 'created')->count())
        ->toBe(1);
});

it('conserve la page source des articles rapatriés', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    fusionner(['--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un']);

    // La citabilité par page vient de `source_locator` : la perdre reviendrait
    // à rapatrier du texte non sourcé.
    expect(articleNumero($tete, '8')->activeVersion()->firstOrFail()->source_locator['page'])->toBe(42);
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
        ->and($donnees[0]['contenu_original'])->toBe('Conformément aux articles 91 et 92 de la')
        // De quoi défaire une fusion qui ouvre une version neuve : la version
        // fermée, sa période d'origine, et les numéros à supprimer.
        ->and($donnees[0]['version_fermee_id'])->not->toBeNull()
        ->and($donnees[0]['version_fermee_periode'])->not->toBeNull()
        ->and($donnees[0]['numeros_rapatries'])->toBe(['8', '9', '10', 'SIGNATURE']);
});

it('refuse --execute sur la connexion de lecture seule', function () {
    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';
    file_put_contents($plan, json_encode([['tete_id' => 'x', 'fragment_id' => 'y']]));

    $this->artisan('mibeko:fusionner-fragments', [
        '--plan' => $plan, '--connection' => 'pgsql_prod_ro', '--execute' => true,
    ])->assertFailed();
});

it('refuse à l\'exécution une paire devenue non fiable depuis le diagnostic', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la');
    fragmentSuspension($jo, 'flux:frag-jo28', 'retrait en cas de non-exécution.');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';
    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--titre-fragment' => 'arrêté pourra faire l’objet d’une suspension ou d’un', '--plan' => $plan,
    ])->assertSuccessful();

    // Un éditeur corrige l'article 7 à la main entre le diagnostic et
    // l'autorisation humaine : le plan a vieilli, la fusion doublerait le texte.
    $article7 = articleNumero($tete, '7');
    $article7->activeVersion()->firstOrFail()->update([
        'contenu_texte' => 'Conformément aux articles 91 et 92 de la Constitution.',
    ]);

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--plan' => $plan, '--execute' => true,
    ])->assertSuccessful();

    expect($article7->activeVersion()->firstOrFail()->contenu_texte)
        ->toBe('Conformément aux articles 91 et 92 de la Constitution.')
        ->and(Article::where('document_id', $tete->id)->count())->toBe(7);
});

it('apparie un fragment à intitulé unique avec l\'acte qui le précède dans le JO, sans --titre-fragment', function () {
    // ~139 fragments de production portent un intitulé unique (la coupure est
    // tombée sur une phrase différente) : le mode par intitulé partagé ne les
    // voit pas, l'adjacence dans le JO les atteint.
    $jo = OfficialJournal::factory()->create()->id;

    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la', 'flux:jo-28_acte_1');
    $fragment = fragmentSuspension($jo, 'flux:jo-28_acte_2', 'retrait en cas de non-exécution.', 'intitulé unique, vu nulle part ailleurs');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql',
        '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($plan), true);

    expect($resultat)->toHaveCount(1)
        ->and($resultat[0]['tete_id'])->toBe($tete->id)
        ->and($resultat[0]['fragment_id'])->toBe($fragment->id);
});

it('n\'apparie pas à travers un acte intercalé entre la tête et la suite', function () {
    $jo = OfficialJournal::factory()->create()->id;

    arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la', 'flux:jo-28_acte_1');

    // Un acte complet s'intercale : la chaîne est rompue, l'appariement à
    // distance serait une supposition.
    $intercale = LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 1551 du 18 juin 2025 portant nomination.',
        'official_journal_id' => $jo,
        'curation_status' => 'published',
        'document_key' => 'flux:jo-28_acte_2',
    ]);
    $articleIntercale = Article::factory()->create(['document_id' => $intercale->id, 'numero_article' => '3', 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create(['article_id' => $articleIntercale->id, 'contenu_texte' => 'Un article complet et sans rapport.']);

    fragmentSuspension($jo, 'flux:jo-28_acte_3', 'retrait en cas de non-exécution.', 'intitulé unique');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';
    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--rapport' => $rapport, '--plan' => $plan,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($plan), true))->toBe([]);

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('ecarte')
        ->and($resultat['raison'])->toContain('aucun acte tronqué ne la précède');
});

it('écarte une suite d\'article que rien ne précède dans son JO', function () {
    $jo = OfficialJournal::factory()->create()->id;
    fragmentSuspension($jo, 'flux:jo-28_acte_1', 'retrait en cas de non-exécution.', 'intitulé unique');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('ecarte')
        ->and($resultat['tete_id'])->toBeNull();
});

it('restreint le diagnostic au Journal officiel demandé', function () {
    $jo1 = OfficialJournal::factory()->create()->id;
    $jo2 = OfficialJournal::factory()->create()->id;

    $tete1 = arreteTete($jo1, 1550, 'Conformément aux articles 91 et 92 de la', 'flux:jo-28_acte_1');
    fragmentSuspension($jo1, 'flux:jo-28_acte_2', 'retrait en cas de non-exécution.', 'intitulé unique jo1');

    arreteTete($jo2, 1560, 'Conformément aux articles 91 et 92 de la', 'flux:jo-29_acte_1');
    fragmentSuspension($jo2, 'flux:jo-29_acte_2', 'retrait en cas de non-exécution.', 'intitulé unique jo2');

    $plan = tempnam(sys_get_temp_dir(), 'plan_').'.json';

    $this->artisan('mibeko:fusionner-fragments', [
        '--connection' => 'pgsql', '--jo' => $jo1, '--plan' => $plan,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($plan), true);

    expect($resultat)->toHaveCount(1)
        ->and($resultat[0]['tete_id'])->toBe($tete1->id);
});

it('fusionne en mode adjacence exactement comme en mode intitulé partagé', function () {
    $jo = OfficialJournal::factory()->create()->id;
    $tete = arreteTete($jo, 1550, 'Conformément aux articles 91 et 92 de la', 'flux:jo-28_acte_1');
    $fragment = fragmentSuspension($jo, 'flux:jo-28_acte_2', 'retrait en cas de non-exécution.', 'intitulé unique');

    fusionner();

    expect(articleNumero($tete, '7')->activeVersion()->firstOrFail()->contenu_texte)
        ->toBe("Conformément aux articles 91 et 92 de la\nretrait en cas de non-exécution.")
        ->and(LegalDocument::withTrashed()->find($fragment->id)->trashed())->toBeTrue();
});
