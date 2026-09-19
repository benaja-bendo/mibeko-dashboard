<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

/**
 * Décision du 19/09/2026 (dashboard#166) : deux actions distinctes dans
 * l'UI, l'une pour corriger (jamais de fork), l'autre pour amender (fork
 * toujours, texte modificateur obligatoire).
 *
 * Mesuré en production le même jour : 2 486 articles avaient des « versions »
 * dont les dates de début coïncidaient avec des campagnes de réingestion,
 * pas avec un amendement réel — `modifie_par_document_id` vide sur les
 * 37 594 lignes d'`article_versions`. Ces tests verrouillent que ça ne se
 * reproduit plus par ce chemin.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

function articleAvecVersion(string $contenu = 'Texte initial.', string $debut = '2020-01-01'): Article
{
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenu,
        'validity_period' => "[{$debut},)",
    ]);

    return $article->refresh();
}

// ── Correction : jamais de fork ──────────────────────────────────────────────

it('corrige en place le même jour', function () {
    $article = articleAvecVersion('Texte avec une coquille.');

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(1)
        ->and($article->activeVersion()->first()->contenu_texte)->toBe('Texte corrigé.');
});

it('corrige en place un jour DIFFÉRENT de celui où la version a commencé — ne fork JAMAIS (dashboard#166)', function () {
    // La version a débuté il y a 5 ans : avant #166, ArticleController
    // aurait fermé cette version et ouvert une nouvelle « à aujourd'hui »,
    // exactement le défaut mesuré en production le 19/09.
    $article = articleAvecVersion('Texte avec une coquille.', now()->subYears(5)->toDateString());
    $versionOriginale = $article->activeVersion()->first();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(1)
        ->and($article->activeVersion()->first()->id)->toBe($versionOriginale->id)
        ->and($article->activeVersion()->first()->contenu_texte)->toBe('Texte corrigé.')
        // La période de validité n'a pas bougé : ce n'est pas un amendement.
        ->and($article->activeVersion()->first()->validity_start)->toBe($versionOriginale->validity_start);
});

it('trace la correction dans l\'audit sans forker de version', function () {
    $article = articleAvecVersion('Texte avec une coquille.', now()->subYears(5)->toDateString());

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    $version = $article->activeVersion()->first();
    expect(DB::table('audits')
        ->where('auditable_type', ArticleVersion::class)
        ->where('auditable_id', $version->id)
        ->where('event', 'updated')
        ->exists())->toBeTrue();
});

it('pose une première version ouverte quand l\'article n\'en a aucune (constat, pas un amendement)', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Premier texte.'])
        ->assertOk();

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(1)
        ->and($article->activeVersion()->first()->contenu_texte)->toBe('Premier texte.');
});

// ── Amendement : fork toujours, texte modificateur obligatoire ───────────────

it('refuse un amendement sans texte modificateur', function () {
    $article = articleAvecVersion();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Texte modifié par le nouvel acte.',
            'start_date' => now()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('modifie_par_document_id');

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(1);
});

it('refuse un amendement dont le texte modificateur n\'existe pas', function () {
    $article = articleAvecVersion();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Texte modifié.',
            'start_date' => now()->toDateString(),
            'modifie_par_document_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('modifie_par_document_id');
});

it('enregistre un amendement : ferme l\'ancienne version, en ouvre une nouvelle avec le texte modificateur', function () {
    $article = articleAvecVersion('Texte avant amendement.', '2020-01-01');
    $ancienneVersion = $article->activeVersion()->first();
    $modificateur = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Texte après amendement.',
            'start_date' => '2025-06-20',
            'modifie_par_document_id' => $modificateur->id,
        ])
        ->assertOk();

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(2);

    $nouvelle = $article->activeVersion()->first();
    expect($nouvelle->contenu_texte)->toBe('Texte après amendement.')
        ->and($nouvelle->modifie_par_document_id)->toBe($modificateur->id)
        ->and($nouvelle->validity_start)->toBe('2025-06-20');

    $ancienneVersion->refresh();
    expect($ancienneVersion->validity_period)->toContain('2025-06-20');
});

it('refuse un amendement dont la date d\'effet précède la version la plus récente', function () {
    $article = articleAvecVersion('Texte.', '2020-01-01');
    $modificateur = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Amendement rétroactif.',
            'start_date' => '2015-01-01',
            'modifie_par_document_id' => $modificateur->id,
        ])
        ->assertStatus(422);

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(1);
});

it('corrige un amendement enregistré le même jour plutôt que d\'en empiler un doublon', function () {
    $article = articleAvecVersion('Texte.', '2020-01-01');
    $modificateur = LegalDocument::factory()->create();
    $second = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Première saisie.',
            'start_date' => '2025-06-20',
            'modifie_par_document_id' => $modificateur->id,
        ])->assertOk();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/articles/{$article->id}/versions", [
            'content' => 'Texte corrigé de l\'amendement.',
            'start_date' => '2025-06-20',
            'modifie_par_document_id' => $second->id,
        ])->assertOk();

    expect(ArticleVersion::where('article_id', $article->id)->count())->toBe(2);

    $version = $article->activeVersion()->first();
    expect($version->contenu_texte)->toBe('Texte corrigé de l\'amendement.')
        ->and($version->modifie_par_document_id)->toBe($second->id);
});
