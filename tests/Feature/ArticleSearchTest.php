<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Disable automatic embedding generation to speed up tests and avoid API calls
    ArticleVersionObserver::$shouldSkipEmbeddings = true;

    // Mock the AI service to avoid real API calls during search
    Embeddings::fake();
    AnonymousAgent::fake(['Réponse IA mockée']);

    $this->typeLoi = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    $this->typeDec = DocumentType::create(['code' => 'DEC', 'nom' => 'Décret']);

    $this->doc1 = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);

    $this->doc2 = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret sur la santé',
    ]);

    $this->article1 = Article::factory()->create([
        'document_id' => $this->doc1->id,
        'numero_article' => '123',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article1->id,
        'contenu_texte' => 'Ceci est un article sur le licenciement.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $this->article2 = Article::factory()->create([
        'document_id' => $this->doc2->id,
        'numero_article' => '456',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article2->id,
        'contenu_texte' => 'Ceci est un article sur la sécurité.',
        'validity_period' => '[2020-01-01,)',
    ]);
});

it('can search articles by content (without RAG)', function () {
    $response = $this->getJson('/api/v1/articles/search?q=licenciement');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '123')
        ->assertJsonPath('data.0.content', 'Ceci est un article sur le licenciement.');
});

it('can search articles by number', function () {
    $response = $this->getJson('/api/v1/articles/search?q=456');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '456');
});

it('can filter search results by document type', function () {
    // Both contain 'article' in content, but different types
    $response = $this->getJson('/api/v1/articles/search?q=article&type=LOI');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_type', 'LOI');

    $response = $this->getJson('/api/v1/articles/search?q=article&type=DEC');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_type', 'DEC');
});

it('returns the correct resource structure without RAG', function () {
    $response = $this->getJson('/api/v1/articles/search?q=licenciement');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'number',
                    'order',
                    'content',
                    'document_id',
                    'document_title',
                    'document_type',
                    'node_title',
                    'breadcrumb',
                    'validation_status',
                ],
            ],
            'pagination' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
            ],
        ]);
});

it('returns the correct resource structure with RAG', function () {
    // Adding rag=true to force RAG execution
    $response = $this->getJson('/api/v1/articles/search?q=licenciement&rag=true');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'answer',
                'sources' => [
                    '*' => [
                        'id',
                        'number',
                        'order',
                        'content',
                        'document_id',
                        'document_title',
                        'document_type',
                        'node_title',
                        'breadcrumb',
                        'validation_status',
                    ],
                ],
                'pagination' => [
                    'total',
                    'per_page',
                    'current_page',
                    'last_page',
                ],
            ],
        ]);
});

it('requires a search query of at least 2 characters', function () {
    $response = $this->getJson('/api/v1/articles/search?q=a');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

/**
 * mibeko-dashboard#14 — avant ce correctif, un simple nombre de mots (≥ 4) ou
 * un point d'interrogation suffisait à déclencher un appel LLM complet sans
 * plafond dédié, anonymement. Seul `rag=` déclenche désormais le RAG.
 */
it('ne déclenche plus le RAG sur un nombre de mots (ancienne heuristique)', function () {
    // 6 mots, aucune ponctuation : sous l'ancien code, wordCount >= 4 suffisait.
    $response = $this->getJson('/api/v1/articles/search?q=le régime applicable au licenciement collectif');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => ['id', 'number', 'content'],
            ],
        ]);
    expect($response->json('data.answer'))->toBeNull();
});

it('ne déclenche plus le RAG sur un point d\'interrogation seul', function () {
    // Sous l'ancien code, $isQuestion suffisait même à 1 mot.
    $response = $this->getJson('/api/v1/articles/search?q=licenciement ?');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'number', 'content'],
            ],
        ]);
});

it('plafonne le RAG anonyme via le limiteur ai_assistant partagé, sans faire échouer la recherche', function () {
    // Désactive le passage des middlewares de ROUTE (`throttle:api`, 2/min en
    // environnement de test — AppServiceProvider), que 5-6 appels en boucle
    // saturerait sans rapport avec ce que ce test vérifie. Forme SANS argument
    // délibérée : `withoutMiddleware(ThrottleRequests::class)` remplacerait le
    // binding du conteneur pour TOUTE la classe par un bouchon inerte — or
    // `ragAllowedByThrottle()` résout cette même classe à la main, hors du
    // pipeline de route, pour vérifier le plafond `ai_assistant` ; un tel
    // remplacement l'aurait neutralisée aussi. La forme sans argument ne fait
    // que sauter l'étape de middleware du routeur, sans toucher au conteneur.
    $this->withoutMiddleware();

    // Limiteur anonyme partagé avec les autres surfaces IA (AppServiceProvider,
    // 'ai_assistant') : 5/minute par IP. Les 5 premières demandes explicites de
    // RAG passent, la 6e dégrade en silence vers la recherche seule (200, pas
    // de 429) plutôt que de faire échouer toute la requête.
    for ($i = 0; $i < 5; $i++) {
        $response = $this->getJson('/api/v1/articles/search?q=licenciement&rag=true');
        $response->assertStatus(200);
        expect($response->json('data.answer'))->not->toBeNull();
    }

    $response = $this->getJson('/api/v1/articles/search?q=licenciement&rag=true');

    $response->assertStatus(200);
    expect($response->json('data.answer'))->toBeNull();
});

it('applique le quota du rôle, pas le plafond anonyme, pour un utilisateur authentifié', function () {
    // Cf. commentaire du test précédent : seul le plafond générique de route
    // est désactivé, pas le plafond `ai_assistant` vérifié à la main.
    $this->withoutMiddleware();

    // Quota standard par défaut (config('ai.quotas.standard.per_minute')) très
    // au-dessus de 5 : les 6 appels doivent tous réussir, contrairement au test
    // anonyme ci-dessus — preuve que ragAllowedByThrottle() distingue bien les
    // deux, sans dupliquer la politique du limiteur nommé.
    $user = User::factory()->create();

    for ($i = 0; $i < 6; $i++) {
        $response = $this->actingAs($user)
            ->getJson('/api/v1/articles/search?q=licenciement&rag=true');
        $response->assertStatus(200);
        expect($response->json('data.answer'))->not->toBeNull();
    }
});

it('résout le contexte d\'un article isolé (document parent inclus)', function () {
    $response = $this->getJson("/api/v1/articles/{$this->article1->id}/context");

    $response->assertSuccessful()
        ->assertJsonPath('data.id', $this->article1->id)
        ->assertJsonPath('data.document_id', $this->doc1->id)
        ->assertJsonPath('data.number', '123');
});

it('renvoie 404 pour un contexte d\'article inconnu', function () {
    $this->getJson('/api/v1/articles/'.Str::uuid().'/context')
        ->assertStatus(404);
});

it('ne génère pas d’embedding quand le lexical suffit déjà', function () {
    // Générer l'embedding de la requête est un appel réseau que cette route
    // payait à CHAQUE recherche, y compris celles que le plein-texte satisfait
    // déjà : c'est ce qui la faisait osciller entre 0,6 s et 3,7 s en
    // production (mesuré le 25/08/2026, mibeko-dashboard#50).
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le bail commercial',
        'curation_status' => 'published',
    ]);

    // Au-delà de `rappelSuffisant` (10) : le lexical se suffit à lui-même.
    for ($i = 1; $i <= 12; $i++) {
        $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => (string) $i]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Le preneur du bail commercial jouit du local loué, disposition numéro {$i}.",
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    $appels = 0;
    Embeddings::fake(function () use (&$appels) {
        $appels++;

        return [array_fill(0, 1024, 0.1)];
    });

    $this->getJson('/api/v1/search?q='.urlencode('bail commercial'))
        ->assertStatus(200)
        ->assertJsonPath('pagination.total', 12);

    expect($appels)->toBe(0);
});

it('génère l’embedding quand le lexical ne trouve presque rien', function () {
    // Cas inverse : aucun recoupement lexical, le rappel conceptuel doit jouer.
    $appels = 0;
    Embeddings::fake(function () use (&$appels) {
        $appels++;

        return [array_fill(0, 1024, 0.1)];
    });

    $this->getJson('/api/v1/search?q='.urlencode('zzzqxwv introuvable'))->assertStatus(200);

    expect($appels)->toBeGreaterThan(0);
});
