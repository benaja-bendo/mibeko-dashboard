<?php

use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'editor', 'user_pro'] as $role) {
        Role::findOrCreate($role);
    }

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create(['status' => 'active']);
    $this->proUser->assignRole('user_pro');

    // Repart d'une table d'audit propre : la création des users ci-dessus a
    // généré des entrées « created » qui fausseraient les comptages.
    Audit::query()->delete();
});

/** Crée une entrée d'audit contrôlée (event/type/date). */
function makeAuditRow(array $overrides = []): Audit
{
    $audit = Audit::create(array_merge([
        'event' => 'updated',
        'auditable_type' => Institution::class,
        'auditable_id' => (string) Str::uuid(),
        'old_values' => ['nom' => 'Ancien'],
        'new_values' => ['nom' => 'Nouveau'],
        'url' => 'http://localhost/test',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
    ], $overrides));

    if (isset($overrides['created_at'])) {
        Audit::where('id', $audit->id)->update(['created_at' => $overrides['created_at']]);
    }

    return $audit;
}

// ---------------------------------------------------------------------------
// Accès
// ---------------------------------------------------------------------------

it('refuse le journal sans authentification', function () {
    $this->getJson('/api/v1/admin/audits')->assertUnauthorized();
});

it('refuse le journal à un non-admin', function () {
    $this->actingAs($this->proUser)->getJson('/api/v1/admin/audits')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Fil & lisibilité
// ---------------------------------------------------------------------------

it('liste le journal avec un payload lisible', function () {
    makeAuditRow(['event' => 'updated', 'new_values' => ['nom' => 'Assemblée Nationale']]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits?period=all')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'event', 'event_label', 'actor', 'object' => ['type', 'type_label', 'id', 'label', 'link'], 'summary', 'changes', 'created_at']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ])
        ->assertJsonPath('data.0.object.type_label', 'Institution');
});

it('filtre par événement', function () {
    makeAuditRow(['event' => 'created']);
    makeAuditRow(['event' => 'deleted']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits?period=all&event=deleted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'deleted');
});

it('filtre par type d\'objet', function () {
    makeAuditRow(['auditable_type' => Institution::class]);
    makeAuditRow(['auditable_type' => User::class, 'auditable_id' => $this->proUser->id]);

    $type = urlencode(User::class);
    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/audits?period=all&auditable_type={$type}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.object.type', 'User');
});

it('applique le preset « actions sensibles »', function () {
    makeAuditRow(['event' => 'updated', 'auditable_type' => Institution::class]); // non sensible
    makeAuditRow(['event' => 'deleted', 'auditable_type' => Institution::class]); // sensible (deleted)

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits?period=all&preset=sensitive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'deleted');
});

it('limite par défaut aux 7 derniers jours', function () {
    makeAuditRow(['created_at' => now()->subDays(2)]);
    makeAuditRow(['created_at' => now()->subDays(30)]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Couverture des nouveaux modèles audités (dont PK string)
// ---------------------------------------------------------------------------

it('audite une institution modifiée', function () {
    $this->actingAs($this->admin);
    $institution = Institution::create(['nom' => 'AN', 'sigle' => 'AN']);
    $institution->update(['nom' => 'Assemblée Nationale']);

    $this->assertDatabaseHas('audits', [
        'auditable_type' => Institution::class,
        'auditable_id' => $institution->id,
        'event' => 'updated',
    ]);
});

it('audite un type de loi (clé string) — valide la colonne auditable_id varchar', function () {
    $this->actingAs($this->admin);
    DocumentType::create(['code' => 'test_loi', 'nom' => 'Loi de test', 'niveau_hierarchique' => 1]);

    $this->assertDatabaseHas('audits', [
        'auditable_type' => DocumentType::class,
        'auditable_id' => 'test_loi',
        'event' => 'created',
    ]);

    $type = urlencode(DocumentType::class);
    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/audits?period=all&auditable_type={$type}")
        ->assertOk()
        ->assertJsonPath('data.0.object.type_label', 'Type de loi')
        ->assertJsonPath('data.0.object.id', 'test_loi');
});

it('trace un changement de rôles via audit personnalisé', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", ['roles' => ['editor']])
        ->assertOk();

    $this->assertDatabaseHas('audits', [
        'auditable_type' => User::class,
        'auditable_id' => $this->proUser->id,
        'event' => 'roles_updated',
    ]);
});

// ---------------------------------------------------------------------------
// Stats / filtres / détail / export / purge
// ---------------------------------------------------------------------------

it('expose les statistiques d\'activité', function () {
    makeAuditRow(['event' => 'created']);
    makeAuditRow(['event' => 'updated']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits/stats')
        ->assertOk()
        ->assertJsonStructure(['data' => ['today', 'last_7_days', 'last_30_days', 'by_event', 'top_actors']]);
});

it('expose les valeurs de filtres', function () {
    makeAuditRow(['event' => 'created', 'user_id' => $this->admin->id, 'user_type' => User::class]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/audits/filters')
        ->assertOk()
        ->assertJsonStructure(['data' => ['types', 'actors', 'events']]);
});

it('retourne le détail avec le diff', function () {
    $audit = makeAuditRow(['old_values' => ['nom' => 'A'], 'new_values' => ['nom' => 'B']]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/audits/{$audit->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $audit->id)
        ->assertJsonPath('data.changes.0.field', 'nom')
        ->assertJsonPath('data.changes.0.old', 'A')
        ->assertJsonPath('data.changes.0.new', 'B');
});

it('exporte le journal en CSV', function () {
    makeAuditRow();

    $this->actingAs($this->admin)
        ->get('/api/v1/admin/audits/export')
        ->assertOk()
        ->assertDownload();
});

it('purge les entrées au-delà du seuil', function () {
    $old = makeAuditRow(['created_at' => now()->subDays(400)]);
    $recent = makeAuditRow(['created_at' => now()->subDays(10)]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/audits', ['older_than_days' => 365])
        ->assertOk()
        ->assertJsonPath('data.deleted', 1);

    $this->assertDatabaseMissing('audits', ['id' => $old->id]);
    $this->assertDatabaseHas('audits', ['id' => $recent->id]);
});

it('purge via la commande planifiée', function () {
    $old = makeAuditRow(['created_at' => now()->subDays(400)]);
    $recent = makeAuditRow(['created_at' => now()->subDays(10)]);

    $this->artisan('mibeko:prune-audits --days=365')->assertSuccessful();

    $this->assertDatabaseMissing('audits', ['id' => $old->id]);
    $this->assertDatabaseHas('audits', ['id' => $recent->id]);
});
