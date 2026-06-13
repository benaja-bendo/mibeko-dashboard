<?php

use App\Models\Article;
use App\Models\Dossier;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('requires authentication', function () {
    getJson('/api/v1/dossiers')->assertUnauthorized();
    postJson('/api/v1/dossiers/sync', [])->assertUnauthorized();
});

it('returns the authoritative state of the user dossiers', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Dossier::factory()->create(); // dossier d'un autre utilisateur

    Sanctum::actingAs($user);

    getJson('/api/v1/dossiers')
        ->assertOk()
        ->assertJsonCount(1, 'data.dossiers')
        ->assertJsonPath('data.dossiers.0.id', $dossier->id);
});

it('creates dossiers pushed by the client with their articles', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();
    Sanctum::actingAs($user);

    $id = (string) Str::uuid();

    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $id,
            'name' => 'Affaire Moukoko',
            'legal_domain' => 'Travail',
            'tag' => 'URGENT',
            'description' => 'Licenciement contesté',
            'color' => '#8F4C31',
            'created_at' => 1000,
            'updated_at' => 2000,
            'articles' => [[
                'article_id' => $article->id,
                'personal_note' => 'Article clé',
                'added_at' => 1500,
            ]],
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('data.dossiers.0.id', $id)
        ->assertJsonPath('data.dossiers.0.name', 'Affaire Moukoko')
        ->assertJsonPath('data.dossiers.0.articles.0.article_id', $article->id)
        ->assertJsonPath('data.dossiers.0.articles.0.personal_note', 'Article clé');

    expect(Dossier::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('applies last-write-wins when both sides changed', function () {
    $user = User::factory()->create();
    $stale = Dossier::factory()->for($user)->create([
        'name' => 'Version serveur',
        'client_updated_at' => 5000,
    ]);
    Sanctum::actingAs($user);

    // Le client pousse une version plus ancienne : le serveur gagne.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $stale->id,
            'name' => 'Version client périmée',
            'updated_at' => 4000,
        ]],
    ])->assertJsonPath('data.dossiers.0.name', 'Version serveur');

    // Le client pousse une version plus récente : le client gagne.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $stale->id,
            'name' => 'Version client récente',
            'updated_at' => 6000,
        ]],
    ])->assertJsonPath('data.dossiers.0.name', 'Version client récente');
});

it('propagates deletions as tombstones', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create(['client_updated_at' => 5000]);
    Sanctum::actingAs($user);

    postJson('/api/v1/dossiers/sync', ['deleted_ids' => [$dossier->id]])
        ->assertOk()
        ->assertJsonCount(0, 'data.dossiers')
        ->assertJsonPath('data.deleted_ids.0', $dossier->id);

    // Un autre appareil repousse l'ancienne version : la suppression gagne.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $dossier->id,
            'name' => $dossier->name,
            'updated_at' => 5000,
        ]],
    ])->assertJsonCount(0, 'data.dossiers');

    // Une modification postérieure à la suppression restaure le dossier.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $dossier->id,
            'name' => 'Restauré après édition',
            'updated_at' => 9000,
        ]],
    ])->assertJsonPath('data.dossiers.0.name', 'Restauré après édition');
});

it('ignores unknown articles and dossiers of other users', function () {
    $user = User::factory()->create();
    $other = Dossier::factory()->create(['client_updated_at' => 1]);
    Sanctum::actingAs($user);

    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [
            [
                'id' => (string) Str::uuid(),
                'name' => 'Dossier valide',
                'updated_at' => 2000,
                'articles' => [[
                    'article_id' => (string) Str::uuid(), // inconnu : écarté sans erreur
                ]],
            ],
            [
                'id' => $other->id, // appartient à un autre utilisateur : ignoré
                'name' => 'Tentative de détournement',
                'updated_at' => 99999,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data.dossiers')
        ->assertJsonPath('data.dossiers.0.articles', []);

    expect($other->refresh()->name)->not->toBe('Tentative de détournement');
});

it('rejects invalid payloads', function () {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => 'not-a-uuid',
            'name' => 'X',
            'updated_at' => 1,
        ]],
    ])->assertUnprocessable();

    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => (string) Str::uuid(),
            'name' => 'Sans updated_at',
        ]],
    ])->assertUnprocessable();
});
