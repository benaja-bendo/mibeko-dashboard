<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\StructureNode;

/**
 * Remédiation dashboard#166 : retire les versions fermées des articles à
 * versions multiples qui ne sont pas de vrais amendements. Verrous testés :
 * jamais la version active, jamais un article portant ne serait-ce qu'un
 * `modifie_par_document_id` réel, jamais de DELETE physique (SoftDeletes
 * uniquement), idempotence sur un second passage.
 */
beforeEach(function () {
    DocumentType::firstOrCreate(['code' => 'DEC'], ['nom' => 'Décret']);
});

function creerArticleAvecDocumentRemediation(): array
{
    $document = LegalDocument::factory()->create(['type_code' => 'DEC', 'curation_status' => 'published']);
    $node = StructureNode::factory()->create(['document_id' => $document->id, 'sort_order' => 0]);
    $article = Article::create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'validation_status' => 'validated',
    ]);

    return [$document, $article];
}

it('ne touche à rien sans --execute', function () {
    [, $article] = creerArticleAvecDocumentRemediation();
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-01-01', '2026-02-01'),
    ]);
    $active = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-02-01'),
    ]);

    $this->artisan('mibeko:remedier-versions-multiples', ['--connection' => 'pgsql'])->assertSuccessful();

    expect(ArticleVersion::withTrashed()->where('article_id', $article->id)->count())->toBe(2)
        ->and($active->fresh()->trashed())->toBeFalse();
});

it('retire la version fermée et garde la version active intacte', function () {
    [, $article] = creerArticleAvecDocumentRemediation();
    $fermee = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Ancien texte tronqué.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-01-01', '2026-02-01'),
    ]);
    $active = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte corrigé et complet.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-02-01'),
    ]);
    $revert = tempnam(sys_get_temp_dir(), 'revert_').'.json';

    $this->artisan('mibeko:remedier-versions-multiples', [
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => $revert,
    ])->assertSuccessful();

    expect($fermee->fresh()->trashed())->toBeTrue()
        ->and($active->fresh()->trashed())->toBeFalse()
        ->and($active->fresh()->contenu_texte)->toBe('Texte corrigé et complet.')
        // SoftDelete uniquement : la ligne reste lisible via withTrashed(), jamais de DELETE physique.
        ->and(ArticleVersion::withTrashed()->where('id', $fermee->id)->exists())->toBeTrue();

    $inverse = json_decode((string) file_get_contents($revert), true);
    expect($inverse)->toHaveCount(1)
        ->and($inverse[0]['id'])->toBe($fermee->id);
});

it('ne touche jamais un article portant un véritable amendement, même partiellement', function () {
    [, $article] = creerArticleAvecDocumentRemediation();
    $modificateur = LegalDocument::factory()->create(['type_code' => 'DEC']);

    $fermee = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-01-01', '2026-02-01'),
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-02-01'),
        'modifie_par_document_id' => $modificateur->id,
    ]);

    $this->artisan('mibeko:remedier-versions-multiples', ['--connection' => 'pgsql', '--execute' => true])
        ->assertSuccessful();

    expect($fermee->fresh()->trashed())->toBeFalse();
});

it('ignore un article à une seule version', function () {
    [, $article] = creerArticleAvecDocumentRemediation();
    $seule = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2026-01-01'),
    ]);

    $this->artisan('mibeko:remedier-versions-multiples', ['--connection' => 'pgsql', '--execute' => true])
        ->assertSuccessful();

    expect($seule->fresh()->trashed())->toBeFalse();
});

it('respecte --limit et reste idempotent sur un second passage', function () {
    [, $article1] = creerArticleAvecDocumentRemediation();
    [, $article2] = creerArticleAvecDocumentRemediation();

    foreach ([$article1, $article2] as $article) {
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'validity_period' => ArticleVersion::makeValidityPeriod('2026-01-01', '2026-02-01'),
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'validity_period' => ArticleVersion::makeValidityPeriod('2026-02-01'),
        ]);
    }

    $this->artisan('mibeko:remedier-versions-multiples', [
        '--connection' => 'pgsql',
        '--limit' => 1,
        '--execute' => true,
    ])->assertSuccessful();

    $trashedCount = ArticleVersion::onlyTrashed()
        ->whereIn('article_id', [$article1->id, $article2->id])
        ->count();
    expect($trashedCount)->toBe(1);

    // Second passage, sans limite : traite l'article restant, jamais celui déjà remédié.
    $this->artisan('mibeko:remedier-versions-multiples', ['--connection' => 'pgsql', '--execute' => true])
        ->assertSuccessful();

    expect(ArticleVersion::onlyTrashed()->whereIn('article_id', [$article1->id, $article2->id])->count())->toBe(2);
});

it('refuse --execute sur une connexion en lecture seule nommée pgsql_prod_ro', function () {
    $this->artisan('mibeko:remedier-versions-multiples', [
        '--connection' => 'pgsql_prod_ro',
        '--execute' => true,
    ])->assertFailed();
});
