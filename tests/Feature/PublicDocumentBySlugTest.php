<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentRelation;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\StructureNode;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * Couvre la vue publique par slug (`/legal-documents/slug/{slug}`) qui alimente
 * les pages SEO du site vitrine : génération du slug, accès au texte d'un
 * article, et invisibilité des brouillons.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

function publishedCodeWithArticle(string $title, string $articleNumber, string $content): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => $title,
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => $articleNumber,
        'ordre_affichage' => 1,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $content,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $document->refresh();
}

it('génère automatiquement un slug lisible à la création', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '1', 'Texte.');

    expect($document->slug)->toBe('code-de-la-famille');
});

it('déduplique les slugs des documents homonymes', function () {
    $first = publishedCodeWithArticle('Code du Travail', '1', 'A');
    $second = publishedCodeWithArticle('Code du Travail', '1', 'B');

    expect($first->slug)->toBe('code-du-travail');
    expect($second->slug)->toBe('code-du-travail-2');
});

it('expose un document publié par son slug avec l\'index des articles', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '230', 'Le mariage est…');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(200)
        ->assertJsonPath('data.document.slug', 'code-de-la-famille')
        ->assertJsonPath('data.document.titre_officiel', 'Code de la Famille')
        ->assertJsonPath('data.articles.0.number', '230')
        ->assertJsonPath('data.current_article', null);
});

it('renvoie le texte intégral de l\'article demandé', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '230', 'Le mariage est l\'union…');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=230")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.number', '230')
        ->assertJsonPath('data.current_article.content', 'Le mariage est l\'union…');
});

it('expose le sommaire hiérarchique et l\'indicateur PDF', function () {
    $document = publishedCodeWithArticle('Code Forestier', '12', 'Texte.');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(200)
        ->assertJsonPath('data.has_pdf', false)
        ->assertJsonPath('data.structure.nodes.0.parent_id', null)
        ->assertJsonPath('data.structure.nodes.0.articles.0.number', '12');
});

it('n\'annonce un PDF que si un objet source ou un journal est réellement accessible', function () {
    Storage::fake('s3');
    $defaultDisk = config('filesystems.default');
    Storage::fake($defaultDisk);

    $journal = OfficialJournal::factory()->create([
        'file_path' => 'official_journals/jo-accessible.pdf',
    ]);
    Storage::disk($defaultDisk)->put($journal->file_path, 'PDF du journal');

    $document = publishedCodeWithArticle('Code avec source cassée', '1', 'Texte.');
    $document->update(['official_journal_id' => $journal->id]);
    $document->mediaFiles()->create([
        'file_path' => 'source-directe-manquante.pdf',
        'object_key' => 'source-directe-manquante.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'source-directe-manquante.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertSuccessful()
        ->assertJsonPath('data.has_pdf', true);

    Storage::disk($defaultDisk)->delete($journal->file_path);

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertSuccessful()
        ->assertJsonPath('data.has_pdf', false);
});

it('reconstruit la hiérarchie parent-enfant du sommaire depuis le tree_path', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code de Test Hiérarchie',
        'curation_status' => 'published',
    ]);

    $parent = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'LIVRE',
        'numero' => 'PREMIER',
        'tree_path' => 'n_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa',
        'sort_order' => 0,
    ]);
    $child = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'numero' => 'I',
        'tree_path' => 'n_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa.n_bbbbbbbb_bbbb_bbbb_bbbb_bbbbbbbbbbbb',
        'sort_order' => 1,
    ]);

    Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'parent_node_id' => $child->id,
    ]);

    $nodes = collect(
        $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
            ->assertStatus(200)
            ->json('data.structure.nodes')
    )->keyBy('id');

    expect($nodes[$parent->id]['parent_id'])->toBeNull();
    expect($nodes[$child->id]['parent_id'])->toBe($parent->id);
    expect($nodes[$child->id]['articles'][0]['number'])->toBe('1');
});

it('ne rend pas accessible un document non publié', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Brouillon Interne',
        'curation_status' => 'draft',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(404);
});

it('expose les textes liés publiés d\'un article (maillage interne)', function () {
    $source = publishedCodeWithArticle('Code Civil', '10', 'Voir la loi sur les sociétés.');
    $target = publishedCodeWithArticle('Loi sur les Sociétés', '5', 'Dispositions applicables.');

    DocumentRelation::create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'source_article_id' => $source->articles()->first()->id,
        'target_article_id' => $target->articles()->first()->id,
        'relation_type' => 'CITE',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$source->slug}?article=10")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.related.0.document_slug', 'loi-sur-les-societes')
        ->assertJsonPath('data.current_article.related.0.article_number', '5')
        ->assertJsonPath('data.current_article.related.0.type', 'CITE');
});

it('ignore les textes liés non publiés (pas de lien mort)', function () {
    $source = publishedCodeWithArticle('Code de Commerce', '1', 'Référence.');

    $targetDraft = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Texte non publié',
        'curation_status' => 'draft',
    ]);
    $targetArticle = Article::factory()->create(['document_id' => $targetDraft->id, 'numero_article' => '1']);

    DocumentRelation::create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $targetDraft->id,
        'source_article_id' => $source->articles()->first()->id,
        'target_article_id' => $targetArticle->id,
        'relation_type' => 'CITE',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$source->slug}?article=1")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.current_article.related');
});

/**
 * Tableaux : le site ne peut rendre une vraie table que si l'API lui dit qu'il
 * y en a une. Sans ces champs, le contenu ressort en texte — et un tableau
 * ingéré avant la normalisation ressort en balises à l'écran.
 */
function publishedArticleWithLocator(string $title, array $locator, string $content): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => $title,
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => 'TABLEAU_1',
        'ordre_affichage' => 1,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $content,
        'source_locator' => $locator,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $document->refresh();
}

it('expose le format de contenu et les tableaux structurés d\'un article', function () {
    $document = publishedArticleWithLocator('Décret Budgétaire', [
        'page' => 4,
        'content_format' => 'table',
        'tables' => [[
            'caption' => 'Crédits ouverts',
            'headers' => ['Chapitre', 'Montant'],
            'rows' => [['3-2-1', '50.000.000']],
            'line_start' => 0,
            'line_end' => 2,
        ]],
    ], "Chapitre | Montant\n3-2-1 | 50.000.000");

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=TABLEAU_1")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.content_format', 'table')
        ->assertJsonPath('data.current_article.tables.0.caption', 'Crédits ouverts')
        ->assertJsonPath('data.current_article.tables.0.headers', ['Chapitre', 'Montant'])
        ->assertJsonPath('data.current_article.tables.0.rows.0', ['3-2-1', '50.000.000'])
        ->assertJsonPath('data.current_article.tables.0.line_start', 0)
        ->assertJsonPath('data.current_article.tables.0.line_end', 2);
});

it('renvoie une liste de tableaux vide quand l\'article n\'en porte pas', function () {
    $document = publishedCodeWithArticle('Code Simple', '1', 'Texte ordinaire.');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=1")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.content_format', null)
        ->assertJsonPath('data.current_article.tables', []);
});

it('ignore une entrée de tableau mal formée plutôt que de la servir', function () {
    // Le locator est du JSONB écrit par le service Python : une entrée
    // corrompue ne doit pas casser la page publique ni fuiter telle quelle.
    $document = publishedArticleWithLocator('Décret Douteux', [
        'content_format' => 'table',
        'tables' => [
            'pas-un-objet',
            ['headers' => [], 'rows' => []],
            ['headers' => ['A'], 'rows' => [['1'], 'pas-une-rangée']],
        ],
    ], 'A');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=TABLEAU_1")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.current_article.tables')
        ->assertJsonPath('data.current_article.tables.0.headers', ['A'])
        ->assertJsonPath('data.current_article.tables.0.rows', [['1'], []]);
});

/**
 * Lecture continue (`?section=`) : le site vitrine doit pouvoir afficher le
 * texte d'une division entière sans un aller-retour par article, tout en
 * gardant un poids de page borné sur les gros codes.
 */
function publishedCodeWithChapters(): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code de Lecture Continue',
        'curation_status' => 'published',
    ]);

    $livre = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'LIVRE',
        'numero' => '1',
        'titre' => 'Des personnes',
        'tree_path' => 'n_11111111_1111_1111_1111_111111111111',
        'sort_order' => 0,
    ]);
    $premier = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'numero' => '1',
        'titre' => 'Dispositions générales',
        'tree_path' => 'n_11111111_1111_1111_1111_111111111111.n_22222222_2222_2222_2222_222222222222',
        'sort_order' => 0,
    ]);
    $second = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'numero' => '2',
        'titre' => 'De la capacité',
        'tree_path' => 'n_11111111_1111_1111_1111_111111111111.n_33333333_3333_3333_3333_333333333333',
        'sort_order' => 1,
    ]);

    foreach ([[$premier, '1', 1], [$premier, '2', 2], [$second, '3', 3]] as [$node, $numero, $ordre]) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $numero,
            'ordre_affichage' => $ordre,
            'parent_node_id' => $node->id,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Texte de l'article {$numero}.",
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    return $document->refresh();
}

it('ne renvoie aucune section tant que ?section= n\'est pas demandé', function () {
    $document = publishedCodeWithChapters();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(200)
        ->assertJsonPath('data.section', null);
});

it('sert le texte de la première division avec ?section=first', function () {
    $document = publishedCodeWithChapters();

    $response = $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section=first")
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.section.articles')
        ->assertJsonPath('data.section.articles.0.number', '1')
        ->assertJsonPath('data.section.articles.0.content', "Texte de l'article 1.")
        ->assertJsonPath('data.section.articles.1.number', '2')
        ->assertJsonPath('data.section.truncated', false)
        ->assertJsonPath('data.section.previous', null);

    // Le fil de la division porte ses ancêtres, du plus haut à elle-même.
    expect($response->json('data.section.path'))->toBe([
        ['type' => 'LIVRE', 'number' => '1', 'title' => 'Des personnes'],
        ['type' => 'CHAPITRE', 'number' => '1', 'title' => 'Dispositions générales'],
    ]);
    expect($response->json('data.section.next.path.1.number'))->toBe('2');
});

it('sert la division qui contient l\'article demandé avec ?section=auto', function () {
    $document = publishedCodeWithChapters();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=3&section=auto")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.number', '3')
        ->assertJsonCount(1, 'data.section.articles')
        ->assertJsonPath('data.section.articles.0.number', '3')
        ->assertJsonPath('data.section.path.1.title', 'De la capacité')
        ->assertJsonPath('data.section.previous.path.1.number', '1')
        ->assertJsonPath('data.section.next', null);
});

it('accepte l\'identifiant d\'une division et retombe sur la première si elle est inconnue', function () {
    $document = publishedCodeWithChapters();
    $second = StructureNode::query()->where('document_id', $document->id)->where('numero', '2')->firstOrFail();

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section={$second->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.section.id', $second->id)
        ->assertJsonPath('data.section.articles.0.number', '3');

    // Une ancre périmée (réingestion) ne doit jamais faire disparaître le texte.
    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section=".Str::uuid())
        ->assertStatus(200)
        ->assertJsonPath('data.section.articles.0.number', '1');
});

it('plafonne une division volumineuse en caractères sans jamais tronquer un article', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code Volumineux',
        'curation_status' => 'published',
    ]);
    $node = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'numero' => '1',
        'tree_path' => 'n_44444444_4444_4444_4444_444444444444',
        'sort_order' => 0,
    ]);

    // Trois articles de 25 000 caractères : le budget (40 000) tombe pendant
    // le deuxième, qui est malgré tout servi ENTIER — on ne coupe pas la loi.
    foreach (['1', '2', '3'] as $i => $numero) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $numero,
            'ordre_affichage' => $i + 1,
            'parent_node_id' => $node->id,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => str_repeat('a', 25000),
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    $response = $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section=first")
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.section.articles')
        ->assertJsonPath('data.section.truncated', true)
        ->assertJsonPath('data.section.total_articles', 3);

    expect(mb_strlen($response->json('data.section.articles.1.content')))->toBe(25000);
});

it('sert les articles orphelins comme une division sans nœud', function () {
    // Un acte court n'a pas de structure : ses articles pendent au document.
    // La factory rattache d'office un nœud — on le détache pour reproduire ce cas.
    $document = publishedCodeWithArticle('Décret Court', '1', 'Article unique.');
    $document->articles()->update(['parent_node_id' => null]);

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section=first")
        ->assertStatus(200)
        ->assertJsonPath('data.section.id', null)
        ->assertJsonPath('data.section.path', [])
        ->assertJsonPath('data.section.articles.0.content', 'Article unique.');
});

/**
 * Ordre de lecture. `ordre_affichage` est le rang d'un article DANS SA
 * DIVISION, pas dans le document : sur l'Acte uniforme portant droit commercial
 * général, 307 articles ne portent que 22 valeurs distinctes dont 67 à zéro.
 * Trier l'index dessus donnait un enchaînement arbitraire — en production, le
 * 30/08/2026, « suivant » sur l'article 6 menait à l'article 294.
 */
it('classe l\'index des articles dans l\'ordre de lecture, pas sur ordre_affichage', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code à Numérotation Relative',
        'curation_status' => 'published',
    ]);

    // Deux chapitres, chacun renumérotant ses articles à partir de 0 — la forme
    // exacte du corpus réel.
    $chapitres = [];
    foreach ([['1', 'aaaaaaaa'], ['2', 'bbbbbbbb']] as [$numero, $seed]) {
        $chapitres[] = StructureNode::factory()->create([
            'document_id' => $document->id,
            'type_unite' => 'CHAPITRE',
            'numero' => $numero,
            'tree_path' => "n_{$seed}_1111_1111_1111_111111111111",
            'sort_order' => (int) $numero - 1,
        ]);
    }

    foreach ([[0, '1', 0], [0, '2', 1], [0, '3', 2], [1, '4', 0], [1, '5', 1]] as [$chapitre, $numero, $rang]) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $numero,
            'ordre_affichage' => $rang,
            'parent_node_id' => $chapitres[$chapitre]->id,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Article {$numero}.",
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    $numeros = collect(
        $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
            ->assertStatus(200)
            ->json('data.articles')
    )->pluck('number')->all();

    expect($numeros)->toBe(['1', '2', '3', '4', '5']);
});

it('enchaîne les divisions dans l\'ordre du texte, pas dans celui des rangs', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code à Divisions Ordonnées',
        'curation_status' => 'published',
    ]);

    foreach ([['1', 'cccccccc', 0], ['2', 'dddddddd', 1]] as [$numero, $seed, $ordre]) {
        $node = StructureNode::factory()->create([
            'document_id' => $document->id,
            'type_unite' => 'CHAPITRE',
            'numero' => $numero,
            'tree_path' => "n_{$seed}_1111_1111_1111_111111111111",
            'sort_order' => $ordre,
        ]);
        // Le second chapitre porte un rang PLUS PETIT que le premier : trier sur
        // `ordre_affichage` le ferait passer devant.
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $numero === '1' ? '1' : '2',
            'ordre_affichage' => $numero === '1' ? 5 : 0,
            'parent_node_id' => $node->id,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => 'Texte.',
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?section=first")
        ->assertStatus(200)
        ->assertJsonPath('data.section.path.0.number', '1')
        ->assertJsonPath('data.section.articles.0.number', '1')
        ->assertJsonPath('data.section.next.path.0.number', '2');
});

/**
 * Provenance à l'échelle de l'article. `validity_period` ne peut PAS servir de
 * date juridique (sa borne basse vaut la date d'ingestion — 178 646 versions
 * dev portent le 07/08/2026), mais la page du PDF source, elle, est exacte :
 * c'est la seule preuve dont on dispose article par article.
 */
it('expose la page du PDF source de l\'article, et rien quand elle manque', function () {
    $document = publishedArticleWithLocator('Décret Paginé', ['page' => 19], 'Texte de l\'article.');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=TABLEAU_1&section=auto")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.page', 19)
        ->assertJsonPath('data.section.articles.0.page', 19);

    $sansPage = publishedCodeWithArticle('Décret Sans Page', '1', 'Texte.');

    $this->getJson("/api/v1/legal-documents/slug/{$sansPage->slug}?article=1&section=auto")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.page', null)
        ->assertJsonPath('data.section.articles.0.page', null);
});
