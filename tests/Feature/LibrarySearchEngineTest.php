<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Couvre le découplage recherche / IA : l'endpoint web de la Bibliothèque
 * (`/library/search`) est un moteur 100 % PostgreSQL — liste paginée + score,
 * JAMAIS de réponse IA, et filtres serveur respectés.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    // Si l'IA était appelée par erreur, ce texte trahirait la fuite.
    AnonymousAgent::fake(['CETTE REPONSE IA NE DOIT JAMAIS APPARAITRE.']);

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $this->ohada = Institution::factory()->create(['nom' => 'OHADA', 'sigle' => 'OHADA']);

    $docNational = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi nationale sur le contrat de travail',
        'legal_scope' => 'national',
    ]);
    $articleNational = Article::factory()->create([
        'document_id' => $docNational->id,
        'numero_article' => '10',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleNational->id,
        'contenu_texte' => 'Le contrat de travail national obéit aux règles suivantes.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $docOhada = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Acte uniforme OHADA relatif au contrat commercial',
        'legal_scope' => 'ohada',
        'institution_id' => $this->ohada->id,
    ]);
    $articleOhada = Article::factory()->create([
        'document_id' => $docOhada->id,
        'numero_article' => '20',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleOhada->id,
        'contenu_texte' => 'Le contrat commercial OHADA est soumis à ces dispositions.',
        'validity_period' => '[2020-01-01,)',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('retourne une liste paginée avec score et jamais de réponse IA', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'number', 'content', 'document_id', 'document_title',
                    'breadcrumb', 'legal_scope', 'institution', 'date_publication', 'score',
                ],
            ],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ])
        // Découplage IA : aucune synthèse n'est jamais renvoyée par la recherche.
        ->assertJsonMissingPath('answer')
        ->assertJsonMissingPath('data.answer')
        ->assertJsonMissingPath('data.sources');

    expect($response->json('data.0.score'))->toBeNumeric();
});

it('filtre par périmètre OHADA', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&legal_scope=ohada');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '20')
        ->assertJsonPath('data.0.legal_scope', 'ohada');
});

it('filtre par institution', function () {
    $institutionId = $this->ohada->id;
    $response = $this->getJson("/api/v1/library/search?q=contrat&institution_id={$institutionId}");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.institution', 'OHADA');
});

it('respecte la pagination demandée', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&per_page=1');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.last_page', 2);
});

it('rejette un périmètre invalide', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&legal_scope=france');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['legal_scope']);
});

it('rejette une requête trop courte', function () {
    $response = $this->getJson('/api/v1/library/search?q=a');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

it('place l\'article au numéro recherché en tête pour « article 45 »', function () {
    // Un article réellement numéroté 45, sur un sujet précis.
    $code = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code du travail',
        'legal_scope' => 'national',
    ]);
    $art45 = Article::factory()->create(['document_id' => $code->id, 'numero_article' => '45']);
    ArticleVersion::factory()->create([
        'article_id' => $art45->id,
        'contenu_texte' => 'Tout travailleur a droit à un congé payé annuel.',
        'validity_period' => '[2020-01-01,)',
    ]);

    // Bruit : un article qui contient le mot « article » mais n'est pas le 45.
    $arrete = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Arrêté portant dispense d\'apport',
        'legal_scope' => 'national',
    ]);
    $art2 = Article::factory()->create(['document_id' => $arrete->id, 'numero_article' => '2']);
    ArticleVersion::factory()->create([
        'article_id' => $art2->id,
        'contenu_texte' => 'La dispense visée à l\'article premier ci-dessus est accordée pour deux ans.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/library/search?q='.urlencode('article 45'));

    $response->assertStatus(200);

    $numbers = collect($response->json('data'))->pluck('number');

    // L'article 45 est en tête et le bruit « article premier » ne remonte pas au-dessus.
    expect($numbers->first())->toBe('45');
    expect($numbers->contains('2'))->toBeFalse();
});

it('cible l\'article numéroté même avec un thème : « article 45 congé »', function () {
    $code = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code du travail',
        'legal_scope' => 'national',
    ]);
    $art45 = Article::factory()->create(['document_id' => $code->id, 'numero_article' => '45']);
    ArticleVersion::factory()->create([
        'article_id' => $art45->id,
        'contenu_texte' => 'Tout travailleur a droit à un congé payé annuel.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/library/search?q='.urlencode('article 45 congé'));

    $response->assertStatus(200);
    expect(collect($response->json('data'))->pluck('number')->first())->toBe('45');
});

it('expose le Journal Officiel d\'origine sur un résultat de recherche', function () {
    $journal = OfficialJournal::factory()->create([
        'title' => 'Journal Officiel n° 4647',
        'is_published' => true,
    ]);
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Arrêté publié au JO 4647',
        'legal_scope' => 'national',
        'official_journal_id' => $journal->id,
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '2']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'La dispense est accordée pour une durée de deux ans.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/library/search?q='.urlencode('dispense'));

    $response->assertStatus(200)
        ->assertJsonPath('data.0.official_journal_id', $journal->id)
        ->assertJsonPath('data.0.official_journal.title', 'Journal Officiel n° 4647');
});

it('filtre les résultats par journal officiel', function () {
    $journal = OfficialJournal::factory()->create();
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Décret paru au journal ciblé',
        'legal_scope' => 'national',
        'official_journal_id' => $journal->id,
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '3']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le contrat de travail saisonnier est encadré.',
        'validity_period' => '[2020-01-01,)',
    ]);

    // « contrat » matche aussi les textes du beforeEach : le filtre doit ne
    // garder que le texte du journal ciblé.
    $response = $this->getJson('/api/v1/library/search?q=contrat&official_journal_id='.$journal->id);

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_id', $doc->id);
});
