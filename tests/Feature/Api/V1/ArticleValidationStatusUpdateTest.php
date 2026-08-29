<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Changer le statut de validation seul (sans toucher au contenu) restait
 * sans effet : `ArticleController::update` n'écrivait `validation_status`
 * dans une VERSION que dans la branche contenu/zone. Un PATCH ne portant
 * QUE `validation_status` — exactement ce qu'envoient les boutons Validé /
 * Attente / Brouillon du viewer — tombait dans le repli générique en fin de
 * méthode, qui n'écrit que sur `articles.validation_status`. Or l'arbre
 * (`ArticleBriefResource`), les exports et le MCP lisent tous
 * `activeVersion->validation_status` : le bouton cliqué ne changeait jamais
 * ce que l'éditeur voit réapparaître.
 */
beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

function articleAvecVersionActive(string $contenu = 'Texte initial.', string $statut = 'pending'): Article
{
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->forDocument($document->id)->create([
        'validation_status' => $statut,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenu,
        'validation_status' => $statut,
    ]);

    return $article;
}

it('applique le changement de statut à la version active quand aucun contenu n\'est envoyé', function () {
    $article = articleAvecVersionActive(statut: 'pending');

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['validation_status' => 'draft'])
        ->assertOk()
        ->assertJsonPath('data.validation_status', 'draft');

    expect($article->activeVersion()->firstOrFail()->validation_status)->toBe('draft');
});

it('applique le changement de statut même si le contenu envoyé est identique à l\'existant', function () {
    // Reproduit l'éditeur complet (RenameNodeModal côté front), qui renvoie
    // toujours le contenu — modifié ou non — aux côtés du statut.
    $article = articleAvecVersionActive(contenu: 'Texte inchangé.', statut: 'pending');

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", [
            'content' => 'Texte inchangé.',
            'validation_status' => 'validated',
        ])
        ->assertOk();

    $version = $article->activeVersion()->firstOrFail();
    expect($version->validation_status)->toBe('validated')
        ->and($version->is_verified)->toBeTrue();
});

it('marque is_verified à faux pour tout statut autre que validated', function () {
    $article = articleAvecVersionActive(statut: 'validated');
    $article->activeVersion()->firstOrFail()->update(['is_verified' => true]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['validation_status' => 'pending'])
        ->assertOk();

    expect($article->activeVersion()->firstOrFail()->is_verified)->toBeFalse();
});
