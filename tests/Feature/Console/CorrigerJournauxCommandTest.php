<?php

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `mibeko:corriger-journaux` (dashboard#218) : corrige des fiches de JO et le
 * rattachement d'un acte par l'API, après avoir relu l'état en base. Simulation
 * par défaut ; un lot dont une entrée a dérivé n'est jamais exécuté.
 */
function lotJournaux(array $entrees): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'lot_journaux_').'.json';
    file_put_contents($chemin, json_encode($entrees, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

/**
 * Simule l'API : applique le PATCH en base, comme le ferait le contrôleur.
 */
function apiQuiEcrit(): void
{
    Http::fake(function (Request $requete) {
        preg_match('#/(official-journals|legal-documents)/([0-9a-f-]{36})$#', $requete->url(), $m);
        $table = $m[1] === 'official-journals' ? 'official_journals' : 'legal_documents';
        DB::table($table)->where('id', $m[2])->update($requete->data());

        return Http::response(['success' => true], 200);
    });
}

function depublier(OfficialJournal $journal): array
{
    return [
        'cible' => 'journal',
        'id' => $journal->id,
        'libelle' => $journal->title,
        'avant' => ['is_published' => true],
        'apres' => ['is_published' => false],
        'motif' => 'numéro publié sans aucun texte',
    ];
}

afterEach(function () {
    putenv('MIBEKO_API_TOKEN');
});

it('simule sans appel réseau et annonce le nombre exact de lignes', function () {
    Http::fake();
    $journaux = OfficialJournal::factory()->count(2)->create(['is_published' => true]);

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux($journaux->map(fn ($j) => depublier($j))->all()),
        '--connection' => 'pgsql',
    ])
        ->expectsOutputToContain('2 ligne(s) seraient modifiées')
        ->expectsOutputToContain('SIMULATION')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(OfficialJournal::where('is_published', true)->count())->toBe(2);
});

it("refuse tout le lot quand une seule entrée n'est plus dans l'état mesuré", function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake();
    $intact = OfficialJournal::factory()->create(['is_published' => true]);
    $renomme = OfficialJournal::factory()->create(['title' => 'Titre changé depuis le diagnostic']);

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([
            depublier($intact),
            [
                'cible' => 'journal',
                'id' => $renomme->id,
                'avant' => ['title' => 'congo-jo-2026-23-2'],
                'apres' => ['title' => 'Journal officiel n° 23-2026'],
            ],
        ]),
        '--connection' => 'pgsql',
        '--execute' => true,
    ])
        ->expectsOutputToContain('dérive')
        ->assertFailed();

    Http::assertNothingSent();
    expect($intact->fresh()->is_published)->toBeTrue();
});

it('refuse un champ hors liste blanche, même sur une cible connue', function () {
    Http::fake();
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([[
            'cible' => 'document',
            'id' => $document->id,
            'avant' => ['curation_status' => 'draft'],
            'apres' => ['curation_status' => 'published'],
        ]]),
        '--connection' => 'pgsql',
    ])
        ->expectsOutputToContain('hors liste blanche')
        ->assertFailed();

    Http::assertNothingSent();
});

it("refuse d'exécuter sans jeton dans le shell", function () {
    Http::fake();
    $journal = OfficialJournal::factory()->create(['is_published' => true]);

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([depublier($journal)]),
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertFailed();

    Http::assertNothingSent();
});

it('écrit par PATCH, vérifie en base et laisse un lot de retour arrière rejouable', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    apiQuiEcrit();
    $journal = OfficialJournal::factory()->create([
        'title' => 'congo-jo-2026-23-2', 'number' => '2026-23', 'is_published' => true,
    ]);
    $jo = OfficialJournal::factory()->create(['is_published' => true]);
    $decret = LegalDocument::factory()->create(['document_role' => 'FLUX', 'official_journal_id' => null]);
    $retour = storage_path('app/retour-journaux-test.json');

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([
            [
                'cible' => 'journal',
                'id' => $journal->id,
                'libelle' => 'JO n° 2026-23',
                'avant' => ['title' => 'congo-jo-2026-23-2', 'number' => '2026-23'],
                'apres' => ['title' => 'Journal officiel n° 23-2026', 'number' => '23'],
                'motif' => 'intitulé relevé sur le PDF',
            ],
            [
                'cible' => 'document',
                'id' => $decret->id,
                'libelle' => 'Décret n° 2010-523',
                'avant' => ['official_journal_id' => null],
                'apres' => ['official_journal_id' => $jo->id],
                'motif' => 'acte détaché de son numéro',
            ],
        ]),
        '--connection' => 'pgsql',
        '--base-url' => 'https://api.test/api/v1',
        '--rythme' => 0,
        '--revert-file' => $retour,
        '--execute' => true,
    ])
        ->expectsOutputToContain('2/2 ligne(s) modifiée(s) et vérifiée(s)')
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r->url() === "https://api.test/api/v1/official-journals/{$journal->id}"
        && $r['title'] === 'Journal officiel n° 23-2026' && $r['number'] === '23');
    Http::assertSent(fn (Request $r) => $r->url() === "https://api.test/api/v1/legal-documents/{$decret->id}"
        && $r['official_journal_id'] === $jo->id);

    expect($journal->fresh()->title)->toBe('Journal officiel n° 23-2026')
        ->and($decret->fresh()->official_journal_id)->toBe($jo->id);

    $inverse = json_decode(file_get_contents($retour), true);
    expect($inverse[0]['avant'])->toBe(['title' => 'Journal officiel n° 23-2026', 'number' => '23'])
        ->and($inverse[0]['apres'])->toBe(['title' => 'congo-jo-2026-23-2', 'number' => '2026-23'])
        ->and($inverse[1]['apres'])->toBe(['official_journal_id' => null]);

    // Le retour arrière se rejoue avec la même commande.
    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => $retour,
        '--connection' => 'pgsql',
        '--base-url' => 'https://api.test/api/v1',
        '--rythme' => 0,
        '--revert-file' => storage_path('app/retour-journaux-test-2.json'),
        '--execute' => true,
    ])->assertSuccessful();

    expect($journal->fresh()->number)->toBe('2026-23')
        ->and($decret->fresh()->official_journal_id)->toBeNull();

    @unlink($retour);
    @unlink(storage_path('app/retour-journaux-test-2.json'));
});

it('saute une entrée déjà appliquée et limite le lot pilote', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    apiQuiEcrit();
    $dejaFait = OfficialJournal::factory()->create(['is_published' => false]);
    [$premier, $second] = OfficialJournal::factory()->count(2)->create(['is_published' => true])->all();
    $retour = storage_path('app/retour-journaux-pilote.json');

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([depublier($dejaFait), depublier($premier), depublier($second)]),
        '--connection' => 'pgsql',
        '--base-url' => 'https://api.test/api/v1',
        '--rythme' => 0,
        '--limit' => 1,
        '--revert-file' => $retour,
        '--execute' => true,
    ])
        ->expectsOutputToContain('1 ligne(s) seraient modifiées')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect($premier->fresh()->is_published)->toBeFalse()
        ->and($second->fresh()->is_published)->toBeTrue()
        ->and(json_decode(file_get_contents($retour), true))->toHaveCount(1);

    @unlink($retour);
});

it("signale un écart quand l'API répond 200 sans que la base ait changé", function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));
    $journal = OfficialJournal::factory()->create(['is_published' => true]);
    $retour = storage_path('app/retour-journaux-ecart.json');

    $this->artisan('mibeko:corriger-journaux', [
        '--lot' => lotJournaux([depublier($journal)]),
        '--connection' => 'pgsql',
        '--base-url' => 'https://api.test/api/v1',
        '--rythme' => 0,
        '--revert-file' => $retour,
        '--execute' => true,
    ])
        ->expectsOutputToContain('écart après écriture')
        ->assertFailed();

    @unlink($retour);
});
