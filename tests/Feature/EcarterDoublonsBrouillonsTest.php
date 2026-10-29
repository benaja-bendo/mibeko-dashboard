<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Nettoyage des brouillons jumeaux d'un même acte (dashboard#251). Un même
 * Journal officiel publié sous plusieurs fichiers PDF est structuré une fois
 * par fichier (mibeko-python#52) : la commande en garde un, écarte les autres
 * par l'API et referme le signalement de doublon de l'exemplaire conservé.
 */
uses(RefreshDatabase::class);

const TITRE_JUMEAU = 'Arrêté n° 1610 du 17 juillet 2026 portant agrément de la société SPIE';

function brouillon(array $attributs = [], int $articles = 2): LegalDocument
{
    // Un seul type pour tous : la fabrique en tire un nouveau par document, ce qui ferait de chaque paire des jumeaux « de types différents ».
    DocumentType::firstOrCreate(['code' => 'ARR'], ['nom' => 'Arrêté', 'niveau_hierarchique' => 4]);

    // Le mécanisme visé : un même numéro de JO déposé sous plusieurs fichiers PDF (mibeko-python#52).
    static $fichier = 0;
    $fichier++;

    $document = LegalDocument::factory()->create($attributs + [
        'metadata' => ['source_url' => "https://www.sgg.cg/JO/2026/congo-jo-2026-34-variante-{$fichier}.pdf"],
        'type_code' => 'ARR',
        'titre_officiel' => TITRE_JUMEAU,
        'date_signature' => '2026-07-17',
        'date_publication' => '2026-07-17',
        'curation_status' => 'draft',
    ]);
    Article::factory()->count($articles)->create(['document_id' => $document->id]);

    return $document;
}

function signalement(LegalDocument $document, string $type, string $severite = 'blocking'): CurationFlag
{
    return CurationFlag::create([
        'document_id' => $document->id, 'source' => 'conformite',
        'type_probleme' => $type, 'severity' => $severite, 'resolved' => false,
        'description' => 'Signalement de test.',
    ]);
}

function cheminTemporaire(): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'ecarter_');
    unlink($chemin);

    return $chemin.'.json';
}

/** Simule l'API : un DELETE supprime vraiment le document, un PATCH referme vraiment le signalement. */
function apiQuiExecute(): void
{
    Http::fake(function (Request $requete) {
        if ($requete->method() === 'DELETE') {
            LegalDocument::findOrFail(basename($requete->url()))->delete();
        }

        if ($requete->method() === 'PATCH') {
            CurationFlag::findOrFail(basename($requete->url()))->update(['resolved' => true]);
        }

        return Http::response(['success' => true], 200);
    });
}

afterEach(fn () => putenv('MIBEKO_API_TOKEN'));

it('ne touche à rien sans --execute et écrit la liste de ce qu\'il écarterait', function () {
    brouillon();
    brouillon();
    brouillon();
    Http::fake();
    $liste = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--liste' => $liste])
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(LegalDocument::count())->toBe(3)
        ->and(json_decode((string) file_get_contents($liste), true)['ecartes'])->toHaveCount(2);
});

it('garde le jumeau le moins bloqué, puis le plus fourni, puis le plus ancien', function () {
    $bloque = brouillon([], 3);
    signalement($bloque, 'd3_article_ampute_debut');
    $propre = brouillon([], 3);
    $liste = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--liste' => $liste])
        ->assertSuccessful();

    $ecartes = json_decode((string) file_get_contents($liste), true)['ecartes'];
    expect($ecartes)->toHaveCount(1)
        ->and($ecartes[0]['id'])->toBe($bloque->id)
        ->and($ecartes[0]['conserve_id'])->toBe($propre->id);
});

it('ne compte pas le signalement de doublon lui-même pour choisir l\'exemplaire', function () {
    // Les deux portent le signalement de doublon, aucun autre bloquant : à qualité égale, le plus ancien reste.
    $premier = brouillon();
    $second = brouillon();
    signalement($premier, 'doublon_titre_date');
    signalement($second, 'doublon_titre_date');
    $liste = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--liste' => $liste])
        ->assertSuccessful();

    $ecartes = json_decode((string) file_get_contents($liste), true)['ecartes'];
    expect($ecartes)->toHaveCount(1)
        ->and($ecartes[0]['id'])->toBe($second->id)
        ->and($ecartes[0]['conserve_id'])->toBe($premier->id);
});

it('laisse à un humain un groupe qui contient un document publié', function () {
    brouillon();
    brouillon(['curation_status' => 'published']);
    $rapport = cheminTemporaire();
    Http::fake();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    $groupes = json_decode((string) file_get_contents($rapport), true);
    expect($groupes)->toHaveCount(1)
        ->and($groupes[0]['motifs'])->toContain('un membre au moins n\'est pas un brouillon');
});

it('laisse à un humain un groupe dont les jumeaux n\'ont pas le même nombre d\'articles', function () {
    brouillon([], 2);
    brouillon([], 4);
    $rapport = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['motifs'])
        ->toContain('nombres d\'articles différents');
});

it('laisse à un humain deux actes de même titre tirés du même fichier : ce sont deux actes, pas un doublon', function () {
    $fichier = ['metadata' => ['source_url' => 'https://www.sgg.cg/JO/2026/congo-jo-2026-34.pdf']];
    brouillon($fichier);
    brouillon($fichier);
    $rapport = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['motifs'])
        ->toContain('deux jumeaux viennent du même fichier (actes distincts de même titre ?)');
});

it('laisse à un humain des jumeaux tirés de numéros de JO différents ou de source inconnue', function () {
    brouillon(['metadata' => ['source_url' => 'https://www.sgg.cg/JO/2025/congo-jo-2025-24.pdf']]);
    brouillon(['metadata' => ['source_url' => 'https://www.sgg.cg/JO/2023/congo-jo-2023-21.pdf']]);
    brouillon(['metadata' => null]);
    $rapport = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['motifs'])
        ->toContain('pas le même numéro de JO (ou source inconnue)');
});

it('laisse à un humain des jumeaux dont les textes n\'ont pas la même longueur', function () {
    foreach ([1000, 100] as $longueur) {
        $document = brouillon([], 0);
        $article = Article::factory()->create(['document_id' => $document->id]);
        ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => str_repeat('a', $longueur)]);
    }
    $rapport = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['motifs'])
        ->toContain('textes de longueurs très différentes');
});

it('laisse à un humain un groupe dont un jumeau avait déjà des articles supprimés', function () {
    $ancien = brouillon([], 2);
    brouillon([], 1);
    Article::factory()->create(['document_id' => $ancien->id])->delete();
    $rapport = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['motifs'])
        ->toContain('articles déjà supprimés avant (restauration non exacte)');
});

it('écarte les jumeaux par l\'API, jamais l\'exemplaire conservé, puis referme son signalement', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    $gardien = brouillon();
    $jumeauA = brouillon();
    $jumeauB = brouillon();
    $autre = brouillon(['titre_officiel' => 'Décret n° 2026-1 sans jumeau']);
    foreach ([$gardien, $jumeauA, $jumeauB] as $document) {
        signalement($document, 'doublon_titre_date');
    }
    // Départage : seul `$gardien` est exempt de bloquant.
    signalement($jumeauA, 'd3_article_ampute_debut');
    signalement($jumeauB, 'd3_article_ampute_debut');
    apiQuiExecute();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--rythme' => 0, '--liste' => cheminTemporaire(),
    ])->assertSuccessful();

    expect(LegalDocument::pluck('id')->all())->toEqualCanonicalizing([$gardien->id, $autre->id]);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), "/legal-documents/{$jumeauA->id}")
        && $r->hasHeader('Authorization', 'Bearer jeton-de-test'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), $gardien->id) && $r->method() === 'DELETE');
    expect(CurationFlag::where('document_id', $gardien->id)->where('type_probleme', 'doublon_titre_date')->value('resolved'))->toBeTrue();
});

it('relit l\'état avant chaque appel : un jumeau publié entre-temps n\'est jamais supprimé', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    $gardien = brouillon();
    $premier = brouillon();
    $second = brouillon();
    signalement($premier, 'd3_article_ampute_debut');
    signalement($second, 'd3_article_ampute_debut');

    // À la première suppression, quelqu'un publie l'autre jumeau.
    Http::fake(function (Request $requete) use ($premier, $second) {
        $cible = basename($requete->url());
        LegalDocument::findOrFail($cible)->delete();
        $autre = $cible === $premier->id ? $second : $premier;
        LegalDocument::whereKey($autre->id)->update(['curation_status' => 'published']);

        return Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--rythme' => 0, '--liste' => cheminTemporaire(),
    ])->assertSuccessful();

    Http::assertSentCount(1);
    expect(LegalDocument::where('curation_status', 'published')->count())->toBe(1)
        ->and(LegalDocument::whereKey($gardien->id)->exists())->toBeTrue();
});

it('refuse d\'exécuter quand l\'effectif sélectionné diffère de celui annoncé', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    brouillon();
    brouillon();
    Http::fake();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--attendu' => 7, '--liste' => cheminTemporaire(),
    ])->assertFailed();

    Http::assertNothingSent();
    expect(LegalDocument::count())->toBe(2);
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');
    brouillon();
    brouillon();
    Http::fake();

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--connection' => 'pgsql', '--execute' => true])
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuse d\'écraser une liste existante, qui peut porter un retour arrière', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    brouillon();
    brouillon();
    Http::fake();
    $liste = cheminTemporaire();
    file_put_contents($liste, '{"ecartes": []}');

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--liste' => $liste,
    ])->assertFailed();

    Http::assertNothingSent();
});

it('plafonne le lot pilote avec --limit', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    foreach (range(1, 5) as $i) {
        brouillon();
    }
    apiQuiExecute();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--limit' => 2, '--rythme' => 0, '--liste' => cheminTemporaire(),
    ])->assertSuccessful();

    expect(LegalDocument::count())->toBe(3);
});

it('signale le document en échec sans interrompre le lot et le laisse intact', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Sleep::fake();
    $gardien = brouillon();
    $refuse = brouillon();
    $passe = brouillon();
    signalement($gardien, 'doublon_titre_date');
    signalement($refuse, 'd3_article_ampute_debut');
    signalement($passe, 'd3_article_ampute_debut');

    Http::fake(function (Request $requete) use ($refuse) {
        if (str_ends_with($requete->url(), $refuse->id)) {
            return Http::response(['message' => 'Interdit'], 403);
        }
        LegalDocument::findOrFail(basename($requete->url()))->delete();

        return Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--rythme' => 0, '--liste' => cheminTemporaire(),
    ])->assertSuccessful();

    expect(LegalDocument::whereKey($refuse->id)->exists())->toBeTrue()
        ->and(LegalDocument::whereKey($passe->id)->exists())->toBeFalse();
    // Un jumeau reste : le signalement de doublon du gardien ne doit pas être refermé.
    expect(CurationFlag::where('document_id', $gardien->id)->where('type_probleme', 'doublon_titre_date')->value('resolved'))->toBeFalse();
});

it('referme un signalement de doublon devenu faux, sans jumeau vivant', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    $seul = brouillon();
    $signal = signalement($seul, 'doublon_titre_date');
    apiQuiExecute();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--rythme' => 0, '--liste' => cheminTemporaire(),
    ])->assertSuccessful();

    expect($signal->fresh()->resolved)->toBeTrue();
});

// ── Retour arrière ───────────────────────────────────────────────────────────

it('défait une exécution : documents et articles restaurés, signalements rouverts', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    $gardien = brouillon();
    $jumeau = brouillon([], 2);
    signalement($jumeau, 'd3_article_ampute_debut');
    $signal = signalement($gardien, 'doublon_titre_date');
    apiQuiExecute();
    $liste = cheminTemporaire();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--execute' => true, '--rythme' => 0, '--liste' => $liste,
    ])->assertSuccessful();

    expect(LegalDocument::whereKey($jumeau->id)->exists())->toBeFalse()
        ->and($signal->fresh()->resolved)->toBeTrue();

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--annuler' => $liste, '--execute' => true,
    ])->assertSuccessful();

    expect(LegalDocument::whereKey($jumeau->id)->exists())->toBeTrue()
        ->and(Article::where('document_id', $jumeau->id)->count())->toBe(2)
        ->and($signal->fresh()->resolved)->toBeFalse();
});

it('refuse d\'annuler sur la connexion de lecture seule', function () {
    $liste = cheminTemporaire();
    file_put_contents($liste, '{"ecartes": [], "signalements_resolus": []}');

    $this->artisan('mibeko:ecarter-doublons-brouillons', ['--annuler' => $liste, '--execute' => true])
        ->assertFailed();
});

it('n\'annule rien quand la liste ne correspond pas à l\'état réel', function () {
    $vivant = brouillon();
    $liste = cheminTemporaire();
    file_put_contents($liste, json_encode(['ecartes' => [['id' => $vivant->id]], 'signalements_resolus' => []]));

    $this->artisan('mibeko:ecarter-doublons-brouillons', [
        '--connection' => 'pgsql', '--annuler' => $liste, '--execute' => true,
    ])->assertFailed();

    expect(LegalDocument::whereKey($vivant->id)->exists())->toBeTrue();
});
