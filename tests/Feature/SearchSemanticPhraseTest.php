<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Sanctum\Sanctum;

/**
 * API-020 (mibeko-dashboard#233) : la recherche publique allume le filet
 * sémantique pour une question en phrase, et seulement pour elle. L'appel au
 * fournisseur est borné dans le temps, suspendu après un échec, et plafonné
 * pour tout le site.
 */
const QUESTION_EN_PHRASE = 'Que reçoivent mes enfants si mon père meurt ?';

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);
    Sanctum::actingAs(User::factory()->create());

    $this->vecteurQuestion = array_fill(0, 1024, 0.1);
    Embeddings::fake(fn () => [$this->vecteurQuestion]);

    // Aucun mot commun avec la question : seul le filet sémantique le trouve.
    $this->reponse = articleAvecEmbedding(
        'Code de la famille',
        'La dévolution successorale s\'opère au profit des descendants en ligne directe.',
        $this->vecteurQuestion,
    );
});

/**
 * Crée un article publié et lui donne l'embedding voulu.
 *
 * @param  list<float>|null  $vecteur
 */
function articleAvecEmbedding(string $titre, string $contenu, ?array $vecteur): LegalDocument
{
    $document = LegalDocument::factory()->create(['type_code' => 'LOI', 'titre_officiel' => $titre]);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenu,
        'validity_period' => '[2020-01-01,)',
    ]);

    if ($vecteur !== null) {
        DB::update('UPDATE article_versions SET embedding = ?::vector WHERE id = ?', ['['.implode(',', $vecteur).']', $version->id]);
    }

    return $document;
}

it('allume le filet sémantique pour une question en phrase, avec un délai borné', function (string $cache) {
    config(['cache.default' => $cache]);

    $this->getJson('/api/v1/library/search?q='.urlencode(QUESTION_EN_PHRASE))
        ->assertOk()
        ->assertJsonPath('data.0.document_id', $this->reponse->id);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->timeout === 3);
})->with(['array', 'database']);

it('ne l\'allume pas pour un numéro d\'article, un mot-clé, un tri par date ou semantic=0', function (string $parametres) {
    $this->getJson('/api/v1/library/search?'.$parametres)->assertOk();

    Embeddings::assertNothingGenerated();
})->with([
    'numéro d\'article' => 'q='.urlencode('article 41 du code de la famille sur la succession'),
    'mot-clé' => 'q=succession',
    'titre court' => 'q='.urlencode('code de la famille'),
    'tri par date' => 'q='.urlencode(QUESTION_EN_PHRASE).'&sort=date_desc',
    'semantic=0' => 'q='.urlencode(QUESTION_EN_PHRASE).'&semantic=0',
]);

it('classe le premier voisin sémantique devant un article qui ne partage qu\'un mot', function () {
    // Un seul mot commun (« enfants ») et un embedding plus éloigné : avec
    // l'ancien terme (0,25 × similarité), ce leurre passait devant.
    $vecteurEloigne = array_map(fn (int $i) => $i % 2 ? 0.1 : 0.05, range(0, 1023));
    articleAvecEmbedding('Code de la famille, autorité parentale', 'Les enfants mineurs restent sous l\'autorité de leurs parents.', $vecteurEloigne);

    $this->getJson('/api/v1/library/search?q='.urlencode(QUESTION_EN_PHRASE))
        ->assertOk()
        ->assertJsonPath('data.0.document_id', $this->reponse->id);
});

it('suspend le filet après un échec du fournisseur et répond en lexical', function () {
    $appels = 0;
    Embeddings::fake(function () use (&$appels) {
        $appels++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });
    articleAvecEmbedding('Loi sur les successions', 'Les enfants héritent de leur père.', null);

    $this->getJson('/api/v1/library/search?q='.urlencode(QUESTION_EN_PHRASE))
        ->assertOk()
        ->assertJsonPath('data.0.document_title', 'Loi sur les successions');
    $this->getJson('/api/v1/library/search?q='.urlencode('Mes enfants héritent-ils de leur père ?'))->assertOk();

    expect($appels)->toBe(1)
        ->and(Cache::has('recherche:filet-semantique-suspendu'))->toBeTrue();
});

it('plafonne le filet sémantique de la recherche publique pour tout le site', function (string $cache) {
    config(['cache.default' => $cache]);
    $this->freezeTime();
    Cache::put('recherche:filet-semantique-public:'.now()->format('YmdHi'), 60, 120);

    $this->getJson('/api/v1/library/search?q='.urlencode(QUESTION_EN_PHRASE))->assertOk();

    Embeddings::assertNothingGenerated();
})->with(['array', 'database']);
