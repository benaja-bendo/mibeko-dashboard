<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\Tools\SearchLegalDatabase;
use App\Models\AgentConversationMessage;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Laravel\Sanctum\Sanctum;

/**
 * mibeko-dashboard#15 : « je n'ai pas trouvé » suivi d'une réponse quand même,
 * et réponses réduites à une liste de documents. Les deux défauts partent du
 * même point aveugle : un corpus muet renvoyait `[]`, indistinguable d'un
 * résultat vide anodin.
 */
beforeEach(function () {
    Embeddings::fake();
});

/** Fait chercher l'agent (l'outil réel s'exécute) puis répondre. */
function faireChercherPuisRepondre(string $query, string $reponse): void
{
    MibekoIA::fake([
        new ToolCall(id: 'call_1', name: 'SearchLegalDatabase', arguments: ['query' => $query]),
        $reponse,
    ]);
}

it('dit explicitement « aucun extrait » au lieu de rendre une liste vide', function () {
    $charge = json_decode((new SearchLegalDatabase)->handle(
        new Request(['query' => 'licenciement pour faute grave'])
    ), true);

    expect($charge['status'])->toBe(SearchLegalDatabase::AUCUN_EXTRAIT)
        ->and($charge['query'])->toBe('licenciement pour faute grave')
        ->and($charge['message'])->toContain('ne réponds pas de mémoire');
});

it('ne fabrique aucune source à partir d\'une charge de statut', function () {
    $charge = (new SearchLegalDatabase)->handle(new Request(['query' => 'inexistant']));

    // Le défaut d'origine : `json_decode($charge) ?: []` rendait un tableau
    // associatif non vide, donc une « source » fantôme envoyée au client.
    expect(SearchLegalDatabase::extractsFrom($charge))->toBe([]);
});

it('distingue un filtre qui ne désigne rien d\'un corpus muet', function () {
    // Cas observé en rejouant le signalement du 21/06 : le modèle invente le code
    // « ACTE_UNIFORME » (le vrai est « AU »), l'outil ne rend rien, et l'assistant
    // annonce que le corpus ne contient pas un texte pourtant publié.
    DocumentType::create(['code' => 'AU', 'nom' => 'Acte uniforme OHADA']);
    $document = LegalDocument::factory()->create([
        'type_code' => 'AU',
        'titre_officiel' => 'Acte uniforme portant droit commercial général',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '25']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Toute personne physique commerçante demande son immatriculation au registre du commerce.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $tool = new SearchLegalDatabase;

    $filtreInvente = json_decode($tool->handle(new Request([
        'query' => 'registre du commerce',
        'document_type' => 'ACTE_UNIFORME',
    ])), true);

    expect($filtreInvente['status'])->toBe(SearchLegalDatabase::FILTRE_SANS_CORRESPONDANCE)
        // Le message porte les codes réels, pour que le modèle puisse se corriger.
        ->and($filtreInvente['message'])->toContain('AU');

    // Sans le filtre, le même corpus répond.
    $sansFiltre = json_decode($tool->handle(new Request(['query' => 'registre du commerce'])), true);
    expect($sansFiltre)->not->toHaveKey('status');
});

it('annonce le corpus muet quand le filtre, lui, désigne bien un document', function () {
    DocumentType::create(['code' => 'AU', 'nom' => 'Acte uniforme OHADA']);
    LegalDocument::factory()->create([
        'type_code' => 'AU',
        'titre_officiel' => 'Acte uniforme portant droit commercial général',
        'curation_status' => 'published',
    ]);

    $charge = json_decode((new SearchLegalDatabase)->handle(new Request([
        'query' => 'licenciement pour faute grave',
        'document_type' => 'AU',
    ])), true);

    expect($charge['status'])->toBe(SearchLegalDatabase::AUCUN_EXTRAIT);
});

it('signale la non-réponse dans la réponse JSON et dans l\'historique', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    faireChercherPuisRepondre(
        'licenciement pour faute grave',
        'Le corpus Mibeko ne contient pas ce texte.',
    );

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelle est la procédure de licenciement pour faute grave ?',
    ])
        ->assertOk()
        ->assertJsonPath('no_result', true)
        ->assertJsonPath('sources', []);

    $assistant = AgentConversationMessage::where('role', 'assistant')->latest('id')->first();
    expect($assistant->meta['no_result'] ?? false)->toBeTrue();
});

it('ne signale aucune non-réponse quand l\'assistant n\'avait pas à chercher', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    // Salutation : aucun appel d'outil. Une réponse sans source n'est pas pour
    // autant une absence de texte — la confondre étiquetterait à tort la moitié
    // des échanges conversationnels.
    MibekoIA::fake(['Bonjour, en quoi puis-je vous aider ?']);

    $this->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertOk()
        ->assertJsonPath('no_result', false);
});

it('émet un évènement no_result dans le flux SSE', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    faireChercherPuisRepondre(
        'licenciement pour faute grave',
        'Le corpus Mibeko ne contient pas ce texte.',
    );

    $flux = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Procédure de licenciement pour faute grave',
        'stream' => true,
    ])->streamedContent();

    expect($flux)->toContain('event: no_result')
        ->and($flux)->toContain(SearchLegalDatabase::AUCUN_EXTRAIT)
        // Aucune source fantôme n'a été poussée au client.
        ->and($flux)->not->toContain('event: sources');
});

it('resert la non-réponse depuis le cache, sans la transformer en réponse ordinaire', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    $message = 'Procedure de licenciement pour faute grave';
    faireChercherPuisRepondre('licenciement faute grave', 'Le corpus Mibeko ne contient pas ce texte.');
    $this->postJson('/api/v1/assistant/chat', ['message' => $message])->assertOk();

    // Deuxième requête identique : servie du cache, sans appel au modèle.
    MibekoIA::fake([]);
    $this->postJson('/api/v1/assistant/chat', ['message' => $message])
        ->assertOk()
        ->assertJsonPath('cached', true)
        ->assertJsonPath('no_result', true);

    $depuisCache = AgentConversationMessage::where('role', 'assistant')->latest('id')->first();
    expect($depuisCache->meta['no_result'] ?? false)->toBeTrue();
});

it('ne signale aucune non-réponse quand la recherche a trouvé un article', function () {
    // Contre-épreuve : le même chemin, corpus non muet cette fois. Sans elle,
    // rien ne prouve que `no_result` distingue quoi que ce soit.
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '42',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le préavis de licenciement est d\'un mois.',
        'validity_period' => '[2020-01-01,)',
    ]);

    faireChercherPuisRepondre('préavis licenciement', 'Le préavis est d\'un mois [1].');

    $reponse = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quel est le délai de préavis ?',
    ])->assertOk()->assertJsonPath('no_result', false);

    expect($reponse->json('sources'))->not->toBeEmpty();
});
