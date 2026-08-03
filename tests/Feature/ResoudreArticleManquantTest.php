<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * La règle validée cette semaine sur le Code civil : un trou de numérotation
 * dont les articles encadrants sont sur la même page (ou une page adjacente)
 * est déjà présent dans la source, pas une perte d'extraction.
 */
uses(RefreshDatabase::class);

function articleAvecPage(string $documentId, string $numero, ?int $page): Article
{
    $article = Article::factory()->create(['document_id' => $documentId, 'numero_article' => $numero]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'source_locator' => $page !== null ? ['page' => $page] : null,
    ]);

    return $article;
}

function flagArticleManquant(string $documentId, string $description): CurationFlag
{
    return CurationFlag::create([
        'document_id' => $documentId,
        'source' => 'heuristic',
        'type_probleme' => 'article_manquant',
        'severity' => 'blocking',
        'description' => $description,
        'resolved' => false,
    ]);
}

it('classe auto quand les articles encadrants sont sur la même page', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '11', 42);
    articleAvecPage($document->id, '14', 42);
    flagArticleManquant($document->id, 'Article(s) 12-13 absent(s) (2 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('auto');
});

it('classe tolérant quand l\'écart de page est dans la tolérance', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '9', 10);
    articleAvecPage($document->id, '11', 11);
    flagArticleManquant($document->id, 'Article(s) 10 absent(s) (1 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--tolerance' => 1,
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['classe'])->toBe('tolerant');
});

it('classe suspect quand l\'écart de page dépasse la tolérance', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '373', 69);
    articleAvecPage($document->id, '375', 71);
    flagArticleManquant($document->id, 'Article(s) 374 absent(s) (1 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--tolerance' => 1,
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['classe'])->toBe('suspect');
});

it('classe semi-auto quand la page manque d\'un côté', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '5', 12);
    articleAvecPage($document->id, '7', null);
    flagArticleManquant($document->id, 'Article(s) 6 absent(s) (1 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['classe'])->toBe('semi-auto');
});

it('classe suspect quand un article encadrant est introuvable', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '5', 12);
    // Pas d'article numéro 7 en base.
    flagArticleManquant($document->id, 'Article(s) 6 absent(s) (1 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('suspect')
        ->and($resultat['raison'])->toContain('après le trou');
});

it('classe suspect quand le trou est en tout début de document', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '3', 5);
    flagArticleManquant($document->id, 'Article(s) 1-2 absent(s) (2 numéro(s)).');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($rapport), true)[0]['classe'])->toBe('suspect');
});

it('écrit la liste des IDs auto + tolérant, pas les suspects ni semi-auto', function () {
    $document = LegalDocument::factory()->create();

    articleAvecPage($document->id, '11', 42);
    articleAvecPage($document->id, '14', 42);
    $auto = flagArticleManquant($document->id, 'Article(s) 12-13 absent(s) (2 numéro(s)).');

    articleAvecPage($document->id, '373', 69);
    articleAvecPage($document->id, '375', 71);
    flagArticleManquant($document->id, 'Article(s) 374 absent(s) (1 numéro(s)).');

    $liste = tempnam(sys_get_temp_dir(), 'liste_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--liste-resolutions' => $liste,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($liste), true))->toBe([$auto->id]);
});

it('ignore les signalements déjà résolus ou de sévérité warning', function () {
    $document = LegalDocument::factory()->create();
    articleAvecPage($document->id, '11', 42);
    articleAvecPage($document->id, '14', 42);

    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'heuristic', 'type_probleme' => 'article_manquant',
        'severity' => 'blocking', 'description' => 'Article(s) 12-13 absent(s) (2 numéro(s)).', 'resolved' => true,
    ]);
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'heuristic', 'type_probleme' => 'article_manquant',
        'severity' => 'warning', 'description' => 'Article(s) 12-13 absent(s) (2 numéro(s)).', 'resolved' => false,
    ]);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--connection' => 'pgsql',
        '--rapport' => $rapport,
    ])->assertSuccessful();

    // Rien à rapporter : aucun signalement blocking non résolu ne correspond,
    // le fichier n'est pas écrit plutôt que d'écrire un tableau vide trompeur.
    expect(file_exists($rapport))->toBeFalse();
});

// ── Exécution ────────────────────────────────────────────────────────────────

it('refuse --execute sans liste de résolutions', function () {
    $this->artisan('mibeko:resoudre-article-manquant', ['--execute' => true])->assertFailed();
});

it('refuse --execute sans jeton dans le shell', function () {
    $liste = tempnam(sys_get_temp_dir(), 'liste_').'.json';
    file_put_contents($liste, json_encode(['abc']));
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--liste-resolutions' => $liste,
        '--execute' => true,
    ])->assertFailed();
});

it('résout par lot via l\'API en fragmentant à 200 identifiants', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true, 'data' => ['affected' => 200]], 200));

    $ids = array_map(fn ($i) => "id-{$i}", range(1, 250));
    $liste = tempnam(sys_get_temp_dir(), 'liste_').'.json';
    file_put_contents($liste, json_encode($ids));

    $this->artisan('mibeko:resoudre-article-manquant', [
        '--liste-resolutions' => $liste,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.mibeko.fr/api/v1/admin/flags/bulk'
        && $r['action'] === 'resolve' && count($r['ids']) === 200);
    Http::assertSent(fn ($r) => count($r['ids']) === 50);

    putenv('MIBEKO_API_TOKEN');
});
