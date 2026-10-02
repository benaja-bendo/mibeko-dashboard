<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * mibeko-dashboard#229 : à pertinence comparable, l'article d'un texte en
 * vigueur passe avant celui d'un texte abrogé. Mesuré le 02/10/2026 :
 * « conditions candidat élection président » sortait en tête l'article 69 de
 * la Constitution de 1992, abrogée et marquée comme telle.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    DocumentType::create(['code' => 'CONST', 'nom' => 'Constitution']);

    // Deux constitutions, même article, même texte : seul le statut diffère.
    foreach (['Constitution du 15 mars 1992' => 'abroge', 'Constitution du 25 octobre 2015' => 'vigueur'] as $titre => $statut) {
        $document = LegalDocument::factory()->create([
            'type_code' => 'CONST',
            'titre_officiel' => $titre,
            'statut' => $statut,
        ]);
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '66']);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Nul ne peut être candidat à l'élection du Président de la République s'il n'est de nationalité congolaise.",
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    Sanctum::actingAs(User::factory()->create());
});

it('place le texte en vigueur devant le texte abrogé, sans faire disparaître ce dernier', function () {
    $this->getJson('/api/v1/library/search?q='.urlencode('conditions candidat élection président'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.document_title', 'Constitution du 25 octobre 2015')
        ->assertJsonPath('data.1.document_title', 'Constitution du 15 mars 1992');
});

it('trouve encore en tête le texte abrogé que la personne nomme', function () {
    $this->getJson('/api/v1/library/search?q='.urlencode('constitution 1992 article 66'))
        ->assertOk()
        ->assertJsonPath('data.0.document_title', 'Constitution du 15 mars 1992');
});
