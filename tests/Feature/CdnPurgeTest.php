<?php

use App\Jobs\PurgeCdnCache;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use App\Services\Cdn\CloudflarePurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Purge du cache Cloudflare (mibeko-dashboard#161). Couvre le garde-fou de
 * configuration (jamais d'appel réseau ni de job mis en file sans
 * CLOUDFLARE_ZONE_ID/API_TOKEN), les quatre déclencheurs automatiques
 * (publication, dépublication, article publié modifié, régénération de
 * slugs), et la commande manuelle.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin'));
    Permission::findOrCreate('documents.update')->assignRole('admin');
});

function cdnTestPublishableDocument(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
    ], $attributes));
    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

// ── Le garde-fou de configuration ────────────────────────────────────────────

it('ne met rien en file sans CLOUDFLARE_ZONE_ID/API_TOKEN', function () {
    config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
    Bus::fake();

    $document = cdnTestPublishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Bus::assertNotDispatched(PurgeCdnCache::class);
});

it('n\'émet aucun appel réseau quand la configuration est absente', function () {
    config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
    Http::fake();

    $resultat = app(CloudflarePurger::class)->purgeEverything();

    expect($resultat['success'])->toBeTrue();
    Http::assertNothingSent();
});

// ── Les déclencheurs ──────────────────────────────────────────────────────────

it('purge à la publication unitaire', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    Bus::fake();

    $document = cdnTestPublishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('purge à la dépublication unitaire (admin, avec motif)', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $document = cdnTestPublishableDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    Bus::fake();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_REVIEW,
            'motif' => 'texte retiré sur demande',
        ])
        ->assertOk();

    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('ne purge pas un PATCH qui ne touche pas curation_status', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $document = cdnTestPublishableDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    Bus::fake();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'libelle_descriptif' => 'Objet de l\'acte',
            'libelle_descriptif_source' => 'manuel',
        ])
        ->assertOk();

    Bus::assertNotDispatched(PurgeCdnCache::class);
});

it('purge à la publication en masse', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $documents = collect([cdnTestPublishableDocument(), cdnTestPublishableDocument()]);

    Bus::fake();

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    // Un seul appel pour tout le lot : la purge est purge_everything, pas
    // une purge par document.
    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('purge quand le contenu d\'un article PUBLIÉ change', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte initial.',
        'validity_period' => '[2020-01-01,)',
    ]);

    Bus::fake();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('ne purge pas quand le contenu d\'un article en BROUILLON change', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_DRAFT]);
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte initial.',
        'validity_period' => '[2020-01-01,)',
    ]);

    Bus::fake();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    Bus::assertNotDispatched(PurgeCdnCache::class);
});

it('purge après un lot de mibeko:corriger-slugs --execute qui touche des lignes', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $document = LegalDocument::factory()->create(['slug' => 'slug-tronque']);
    $mapping = tempnam(sys_get_temp_dir(), 'slugs_').'.json';
    file_put_contents($mapping, json_encode([['id' => $document->id, 'slug' => 'slug-complet']]));

    Bus::fake();

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => $mapping,
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertSuccessful();

    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('ne purge pas un mibeko:corriger-slugs qui n\'a rien corrigé', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);

    $mapping = tempnam(sys_get_temp_dir(), 'slugs_').'.json';
    file_put_contents($mapping, json_encode([]));

    Bus::fake();

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => $mapping,
        '--connection' => 'pgsql',
        '--execute' => true,
    ]);

    Bus::assertNotDispatched(PurgeCdnCache::class);
});

// ── Le job lui-même ───────────────────────────────────────────────────────────

it('le job appelle Cloudflare et journalise le succès', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    (new PurgeCdnCache)->handle(app(CloudflarePurger::class));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-test/purge_cache'
            && $request['purge_everything'] === true
            && $request->hasHeader('Authorization', 'Bearer token-test');
    });
});

it('le job relève une exception (donc un retry) quand Cloudflare échoue', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'invalid token']]], 403),
    ]);

    expect(fn () => (new PurgeCdnCache)->handle(app(CloudflarePurger::class)))
        ->toThrow(RuntimeException::class);
});

it('deux dispatch rapprochés ne mettent en file qu\'un seul job (ShouldBeUnique)', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    // `QUEUE_CONNECTION=sync` en test exécuterait le job dans l'instant (donc
    // un vrai appel réseau) : on force le driver `database` pour CE test
    // précis, qui vérifie la mise en file, pas l'exécution.
    config(['queue.default' => 'database']);

    PurgeCdnCache::dispatch()->delay(now()->addMinute());
    PurgeCdnCache::dispatch()->delay(now()->addMinute());

    // Le verrou d'unicité empêche la seconde mise en file : une seule ligne
    // dans la table `jobs`.
    expect(DB::table('jobs')->count())->toBe(1);
});

// ── La commande manuelle ──────────────────────────────────────────────────────

it('mibeko:purge-cdn est un no-op silencieux sans configuration', function () {
    config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
    Http::fake();

    $this->artisan('mibeko:purge-cdn')->assertSuccessful();

    Http::assertNothingSent();
});

it('mibeko:purge-cdn appelle Cloudflare directement (synchrone)', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    $this->artisan('mibeko:purge-cdn')->assertSuccessful();

    Http::assertSentCount(1);
});

it('mibeko:purge-cdn échoue proprement quand Cloudflare refuse', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 403)]);

    $this->artisan('mibeko:purge-cdn')->assertFailed();
});
