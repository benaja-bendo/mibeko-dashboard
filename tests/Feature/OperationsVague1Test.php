<?php

use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Les deux opérations de production de la vague 1 : renommage des documents
 * « Journal officiel n° … » qui portent en réalité un texte unique, et
 * publication par l'API. Toutes deux simulent par défaut ; l'écriture est un
 * geste explicite (docs/infra/production.md § 6).
 */
uses(RefreshDatabase::class);

function fichierTemporaire(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'vague1_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

// ── Renommage ────────────────────────────────────────────────────────────────

it('ne touche à rien sans --execute', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Journal officiel n° 1-2002 — spécial',
        'curation_status' => 'draft',
    ]);

    $this->artisan('mibeko:corriger-titres-jo', [
        '--mapping' => fichierTemporaire([['id' => $document->id, 'titre' => 'Constitution du 20 janvier 2002']]),
        '--connection' => 'pgsql',
    ])->assertSuccessful();

    expect($document->fresh()->titre_officiel)->toBe('Journal officiel n° 1-2002 — spécial');
});

it('renomme et recalcule le slug avec --execute', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Journal officiel n° 1-2002 — spécial',
        'curation_status' => 'draft',
    ]);
    $revert = tempnam(sys_get_temp_dir(), 'revert_').'.json';

    $this->artisan('mibeko:corriger-titres-jo', [
        '--mapping' => fichierTemporaire([['id' => $document->id, 'titre' => 'Constitution du 20 janvier 2002']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => $revert,
    ])->assertSuccessful();

    $fresh = $document->fresh();
    expect($fresh->titre_officiel)->toBe('Constitution du 20 janvier 2002')
        ->and($fresh->slug)->toBe('constitution-du-20-janvier-2002');

    // Le retour arrière porte bien l'état d'origine.
    expect(json_decode((string) file_get_contents($revert), true)[0]['titre'])
        ->toBe('Journal officiel n° 1-2002 — spécial');
});

it('refuse de renommer un document déjà publié', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Journal officiel n° 1-2002 — spécial',
        'curation_status' => 'published',
    ]);

    $this->artisan('mibeko:corriger-titres-jo', [
        '--mapping' => fichierTemporaire([['id' => $document->id, 'titre' => 'Autre titre']]),
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertSuccessful();

    expect($document->fresh()->titre_officiel)->toBe('Journal officiel n° 1-2002 — spécial');
});

it('refuse --execute sur la connexion de diagnostic en lecture seule', function () {
    $this->artisan('mibeko:corriger-titres-jo', [
        '--mapping' => fichierTemporaire([['id' => 'peu-importe', 'titre' => 'Titre']]),
        '--connection' => 'pgsql_prod_ro',
        '--execute' => true,
    ])->assertFailed();
});

// ── Publication ──────────────────────────────────────────────────────────────

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([['id' => 'abc', 'titre' => 'Un arrêté']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse de publier sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([['id' => 'abc', 'titre' => 'Un arrêté']]),
        '--execute' => true,
    ])->assertFailed();
});

it('enchaîne review puis published, la machine à états interdisant le saut direct', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([['id' => 'abc-123', 'titre' => 'Un arrêté']]),
        '--date-inconnue' => true,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);

    Http::assertSent(fn ($r) => $r['curation_status'] === 'review');
    Http::assertSent(fn ($r) => $r['curation_status'] === 'published'
        && ($r['date_entree_vigueur_inconnue'] ?? false) === true);

    putenv('MIBEKO_API_TOKEN');
});

it('signale le document en échec sans interrompre le lot', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();

    // Le premier document échoue à la publication, le second passe.
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return $appels === 2
            ? Http::response(['message' => 'Un document sans article ne peut pas être publié.'], 422)
            : Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([
            ['id' => 'aaa', 'titre' => 'Document vide'],
            ['id' => 'bbb', 'titre' => 'Document valide'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    // 2 appels pour le premier (revue OK, publication refusée) + 2 pour le second.
    // Un 422 est un refus définitif : il ne se réessaie pas.
    Http::assertSentCount(4);

    putenv('MIBEKO_API_TOKEN');
});

it('reprend après un 429 en respectant le Retry-After du serveur', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();

    // Le quota refuse le premier appel, puis tout passe.
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return $appels === 1
            ? Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
            : Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([['id' => 'abc', 'titre' => 'Un arrêté']]),
        '--execute' => true,
    ])->assertSuccessful();

    // L'appel refusé est rejoué : 1 refus + 1 revue + 1 publication.
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration) => $duration->s === 7, times: 1);

    putenv('MIBEKO_API_TOKEN');
});

it('abandonne un document après quatre reprises, sans toucher aux suivants', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();

    // Le premier document tombe systématiquement sur un 503, le second passe.
    Http::fake(fn ($request) => str_contains($request->url(), 'aaa')
        ? Http::response(['message' => 'Service Unavailable'], 503)
        : Http::response(['success' => true], 200));

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([
            ['id' => 'aaa', 'titre' => 'Document malchanceux'],
            ['id' => 'bbb', 'titre' => 'Document valide'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    // 5 tentatives sur le premier (1 + 4 reprises), puis 2 appels pour le second.
    Http::assertSentCount(7);

    putenv('MIBEKO_API_TOKEN');
});

it('écrit les documents non publiés au format --liste pour permettre la reprise', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();

    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return $appels === 2
            ? Http::response(['message' => 'Un document sans article ne peut pas être publié.'], 422)
            : Http::response(['success' => true], 200);
    });

    $echecs = tempnam(sys_get_temp_dir(), 'echecs_').'.json';

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([
            ['id' => 'aaa', 'titre' => 'Document vide'],
            ['id' => 'bbb', 'titre' => 'Document valide'],
        ]),
        '--echecs' => $echecs,
        '--execute' => true,
    ])->assertSuccessful();

    // Le fichier se redonne tel quel à --liste : seul le document en échec y figure.
    expect(json_decode((string) file_get_contents($echecs), true))
        ->toBe([['id' => 'aaa', 'titre' => 'Document vide']]);

    putenv('MIBEKO_API_TOKEN');
});

it('tient la cadence entre les documents, jamais après le dernier', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([
            ['id' => 'aaa', 'titre' => 'Premier'],
            ['id' => 'bbb', 'titre' => 'Deuxième'],
            ['id' => 'ccc', 'titre' => 'Troisième'],
        ]),
        '--rythme' => 30,
        '--execute' => true,
    ])->assertSuccessful();

    // Trois documents, deux pauses : la vague ne s'attarde pas après le dernier.
    Sleep::assertSleptTimes(2);

    putenv('MIBEKO_API_TOKEN');
});

it('n\'attend pas entre les documents quand la cadence est désactivée', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:publier-vague', [
        '--liste' => fichierTemporaire([
            ['id' => 'aaa', 'titre' => 'Premier'],
            ['id' => 'bbb', 'titre' => 'Deuxième'],
        ]),
        '--rythme' => 0,
        '--execute' => true,
    ])->assertSuccessful();

    Sleep::assertNeverSlept();

    putenv('MIBEKO_API_TOKEN');
});
