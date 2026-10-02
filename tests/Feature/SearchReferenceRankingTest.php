<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * mibeko-dashboard#233 : un préambule ou un bloc de signature ne passe plus
 * devant l'article qui répond, et le jeu de questions de référence se rejoue
 * par une commande en lecture seule.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    DocumentType::create(['code' => 'ARR', 'nom' => 'Arrêté']);
    DocumentType::create(['code' => 'CODE', 'nom' => 'Code']);

    $creerArticle = function (string $titre, string $slug, string $type, string $numero, string $texte): void {
        $document = LegalDocument::factory()->create(['type_code' => $type, 'titre_officiel' => $titre, 'slug' => $slug]);
        $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => $numero]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => $texte,
            'validity_period' => '[2020-01-01,)',
        ]);
    };

    // Même texte : seul le numéro distingue le bloc de signature de l'article.
    $texte = "La période d'essai ne peut excéder six mois, renouvellement compris.";
    $creerArticle('Arrêté relatif aux télécommunications', 'arrete-telecom', 'ARR', 'SIGNATURE', $texte);
    $creerArticle('Code du travail', 'code-du-travail', 'CODE', '35', $texte);

    Sanctum::actingAs(User::factory()->create());
});

it('place l\'article qui répond devant un bloc de signature', function () {
    $this->getJson('/api/v1/library/search?q='.urlencode("période d'essai"))
        ->assertOk()
        ->assertJsonPath('data.0.document_title', 'Code du travail')
        ->assertJsonPath('data.1.number', 'SIGNATURE');
});

it('rejoue le jeu de questions de référence et donne le rang de l\'article attendu', function () {
    $jeu = tempnam(sys_get_temp_dir(), 'recherche-reference');
    file_put_contents($jeu, json_encode(['cas' => [
        ['question' => "Combien de temps peut durer ma période d'essai ?", 'attendu' => [['document' => 'code-du-travail', 'articles' => ['35']]], 'rang_max' => 2],
        ['question' => 'Quel préavis pour un agent commercial ?', 'attendu' => [['document' => 'acte-uniforme-absent', 'articles' => ['228']]], 'rang_max' => 3],
    ]]));

    $this->artisan('mibeko:mesurer-recherche', ['--connection' => 'pgsql', '--fichier' => $jeu])
        ->expectsOutputToContain('texte absent')
        ->expectsOutputToContain('Dans la tolérance : 1 sur 1 cas mesurables')
        ->expectsOutputToContain('Embeddings : 2 calculés')
        ->assertSuccessful();

    unlink($jeu);
});

it('n\'écrit rien dans le cache de la base qu\'elle mesure', function () {
    // Le 02/10/2026, une mesure sur la copie de la production y a laissé le
    // compteur du plafond sémantique : le cache suivait la connexion mesurée.
    config(['cache.default' => 'database', 'ai.caching.embeddings.store' => 'database']);
    $jeu = tempnam(sys_get_temp_dir(), 'recherche-reference');
    file_put_contents($jeu, json_encode(['cas' => [
        ['question' => "Combien de temps peut durer ma période d'essai ?", 'attendu' => [['document' => 'code-du-travail', 'articles' => ['35']]], 'rang_max' => 2],
    ]]));

    $this->artisan('mibeko:mesurer-recherche', ['--connection' => 'pgsql', '--fichier' => $jeu])->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(0);

    unlink($jeu);
});
