<?php

use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Embeddings;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// L'audit owen-it résout l'auteur d'une écriture en interrogeant, dans l'ordre,
// les guards listés dans `config/audit.php`. Toutes les routes d'écriture de
// `routes/api.php` passent par `auth:sanctum` : si « sanctum » n'est pas dans
// cette liste, `UserResolver` ne trouve personne et écrit `user_id = NULL`.
//
// C'est exactement ce qui s'est produit en production : mesure du 16/08/2026,
// 100 derniers enregistrements de la table `audits`, 0 porteur de `user_id`.
// Le défaut est resté invisible parce que les tests s'authentifient d'ordinaire
// par `actingAs()`, qui passe par le guard « web » — présent dans la liste.
// Les tests ci-dessous s'authentifient donc par un VRAI jeton Sanctum
// (en-tête Authorization: Bearer), le seul canal qu'emprunte la production.

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->token = $this->editor->createToken('test-device')->plainTextToken;
});

it('attribue l\'audit au porteur du jeton Sanctum lors d\'un PATCH de document', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'code du travaille']);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'titre_officiel' => 'Code du travail',
        ])
        ->assertOk();

    $audit = Audit::query()
        ->where('auditable_type', LegalDocument::class)
        ->where('auditable_id', $document->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull('aucun audit « updated » écrit pour ce document');
    expect($audit->user_id)->toBe($this->editor->id)
        ->and($audit->user_type)->toBe(User::class);
});

it('attribue aussi la suppression d\'un document au porteur du jeton', function () {
    // La suppression est l'écriture dont la trace compte le plus : sans auteur,
    // l'audit dit qu'un texte a disparu sans dire qui l'a fait disparaître.
    // (`url` n'est pas vérifiable ici : sous PHPUnit, `UrlResolver` renvoie
    // « console », l'application ne tournant pas derrière un serveur HTTP.)
    Permission::findOrCreate('documents.delete');
    Role::findOrCreate('editor')->givePermissionTo('documents.delete');
    $document = LegalDocument::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->deleteJson("/api/v1/legal-documents/{$document->id}")
        ->assertOk();

    $audit = Audit::query()
        ->where('auditable_type', LegalDocument::class)
        ->where('auditable_id', $document->id)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull('aucun audit « deleted » écrit pour ce document');
    expect($audit->user_id)->toBe($this->editor->id);
});

it('ne liste aucun guard fantôme dans config/audit.php', function () {
    // `UserResolver` avale l'exception d'un guard inexistant et passe au
    // suivant : une entrée morte ne se voit donc jamais à l'exécution. C'est
    // ainsi qu'un guard « api » — jamais défini dans `config/auth.php`, et que
    // Sanctum ne crée pas non plus — a pu rester des mois dans la liste en
    // donnant l'illusion d'une couverture des routes API.
    $guards = config('audit.user.guards');

    expect($guards)->toBeArray()->not->toBeEmpty();

    $fantomes = [];
    foreach ($guards as $guard) {
        try {
            Auth::guard($guard);
        } catch (InvalidArgumentException $e) {
            $fantomes[] = $guard;
        }
    }

    expect($fantomes)->toBe([], 'guards listés mais non définis : '.implode(', ', $fantomes));
});

it('couvre le guard réellement utilisé par les routes d\'écriture', function () {
    // Garde-fou de régression : toutes les routes d'écriture de routes/api.php
    // sont protégées par `auth:sanctum`.
    expect(config('audit.user.guards'))->toContain('sanctum');
});
