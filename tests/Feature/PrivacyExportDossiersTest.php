<?php

use App\Models\Article;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\DossierGeneratedDocument;
use App\Models\DossierPiece;
use App\Models\DossierReference;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * La politique de confidentialité (§ 2.2 et § 6) promet que l'export remet
 * les dossiers et les favoris de l'usager. Les favoris n'ont pas de table :
 * ce sont les articles des dossiers étiquetés FAVORIS (« Mes Favoris »,
 * créé par l'app mobile).
 */
function exportDossiersDe(User $user): array
{
    $response = test()->actingAs($user)->get('/api/v1/profile/export');
    $response->assertOk();

    return json_decode($response->streamedContent(), true);
}

it('exporte chaque dossier avec ses articles, références, échéances, pièces et documents générés', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Loi n° 45-75 du 15 mars 1975 instituant un code du travail']);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '39']);
    $dossier = Dossier::factory()->create([
        'user_id' => $user->id,
        'name' => 'Licenciement de M. Moukala',
        'tag' => 'EN_COURS',
        'client_name' => 'M. Moukala',
    ]);
    $dossier->articles()->attach($article->id, [
        'personal_note' => 'Préavis non respecté',
        'added_at' => Carbon::parse('2026-09-20 10:00:00')->getTimestampMs(),
    ]);
    DossierReference::factory()->create(['dossier_id' => $dossier->id, 'type' => 'article', 'title' => 'Article 39', 'note' => 'À citer']);
    DossierEcheance::factory()->create(['dossier_id' => $dossier->id, 'title' => 'Audience de conciliation', 'due_date' => '2026-10-15']);
    DossierPiece::factory()->create(['dossier_id' => $dossier->id, 'name' => 'contrat-de-travail.pdf']);
    DossierGeneratedDocument::factory()->create(['dossier_id' => $dossier->id, 'title' => 'Mise en demeure', 'html' => '<p>Monsieur,</p>']);

    $exporte = exportDossiersDe($user)['dossiers'];

    expect($exporte)->toHaveCount(1)
        ->and($exporte[0]['name'])->toBe('Licenciement de M. Moukala')
        ->and($exporte[0]['client_name'])->toBe('M. Moukala')
        ->and($exporte[0]['articles'])->toBe([[
            'article_id' => $article->id,
            'article_number' => '39',
            'document_title' => 'Loi n° 45-75 du 15 mars 1975 instituant un code du travail',
            'personal_note' => 'Préavis non respecté',
            'added_at' => '2026-09-20T10:00:00+00:00',
        ]])
        ->and($exporte[0]['references'][0])->toMatchArray(['type' => 'article', 'title' => 'Article 39', 'note' => 'À citer'])
        ->and($exporte[0]['echeances'][0])->toMatchArray(['title' => 'Audience de conciliation', 'due_date' => '2026-10-15'])
        ->and($exporte[0]['pieces'][0]['name'])->toBe('contrat-de-travail.pdf')
        ->and($exporte[0]['generated_documents'][0])->toMatchArray(['title' => 'Mise en demeure', 'html' => '<p>Monsieur,</p>']);
});

it('liste à part les articles des dossiers étiquetés FAVORIS', function () {
    $user = User::factory()->create();
    [$favoriA, $favoriB, $autre] = Article::factory()->count(3)->create()->all();
    $favoris = Dossier::factory()->create(['user_id' => $user->id, 'name' => 'Mes Favoris', 'tag' => Dossier::TAG_FAVORIS]);
    $favoris->articles()->attach([$favoriA->id => ['added_at' => 1], $favoriB->id => ['added_at' => 2]]);
    $affaire = Dossier::factory()->create(['user_id' => $user->id, 'tag' => 'EN_COURS']);
    $affaire->articles()->attach($autre->id, ['added_at' => 3]);

    $payload = exportDossiersDe($user);

    // Le dossier « Mes Favoris » reste un dossier comme les autres…
    expect($payload['dossiers'])->toHaveCount(2)
        // …et ses articles, seulement eux, sont repris sous `favorites`.
        ->and(array_column($payload['favorites'], 'article_id'))->toBe([$favoriA->id, $favoriB->id]);
});

it('garde un article retiré du corpus et la note personnelle qui l\'accompagne', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create(['numero_article' => '7']);
    $dossier = Dossier::factory()->create(['user_id' => $user->id]);
    $dossier->articles()->attach($article->id, ['personal_note' => 'Ma note', 'added_at' => 0]);
    $article->delete();

    $articles = exportDossiersDe($user)['dossiers'][0]['articles'];

    expect($articles)->toHaveCount(1)
        ->and($articles[0])->toMatchArray(['article_number' => '7', 'personal_note' => 'Ma note', 'added_at' => null]);
});

it('n\'exporte pas les dossiers supprimés', function () {
    $user = User::factory()->create();
    Dossier::factory()->create(['user_id' => $user->id, 'name' => 'Dossier vivant']);
    Dossier::factory()->create(['user_id' => $user->id, 'name' => 'Dossier supprimé'])->delete();

    expect(array_column(exportDossiersDe($user)['dossiers'], 'name'))->toBe(['Dossier vivant']);
});

it('n\'exporte ni dossier ni favori d\'un autre compte', function () {
    $autrui = User::factory()->create();
    $article = Article::factory()->create();
    $favorisAutrui = Dossier::factory()->create(['user_id' => $autrui->id, 'tag' => Dossier::TAG_FAVORIS, 'name' => 'Favoris d\'autrui']);
    $favorisAutrui->articles()->attach($article->id, ['personal_note' => 'Note d\'autrui', 'added_at' => 1]);
    DossierReference::factory()->create(['dossier_id' => $favorisAutrui->id, 'title' => 'Référence d\'autrui']);

    $user = User::factory()->create();
    Dossier::factory()->create(['user_id' => $user->id, 'tag' => 'EN_COURS', 'name' => 'Mon dossier']);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $body = $response->streamedContent();
    $payload = json_decode($body, true);

    expect(array_column($payload['dossiers'], 'name'))->toBe(['Mon dossier'])
        ->and($payload['favorites'])->toBe([])
        ->and($body)->not->toContain('autrui');
});
