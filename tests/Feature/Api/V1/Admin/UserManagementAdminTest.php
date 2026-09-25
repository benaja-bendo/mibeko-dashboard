<?php

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'editor', 'user_pro', 'mobile_user'] as $role) {
        Role::findOrCreate($role);
    }

    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create(['status' => 'active']);
    $this->proUser->assignRole('user_pro');
});

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse l\'annuaire sans authentification', function () {
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
});

it('refuse l\'annuaire à un non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/users')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Annuaire, recherche & filtres
// ---------------------------------------------------------------------------

it('liste les utilisateurs paginés avec leurs rôles', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'status', 'roles', 'is_online', 'two_factor_enabled']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ])
        ->assertJsonPath('pagination.total', 2);
});

it('recherche par nom ou email', function () {
    // Terme volontairement non collisionnable : faker (fr_FR) ne génère jamais
    // « Zzxqv » dans un nom ou un email, contrairement à un patronyme courant.
    User::factory()->create(['name' => 'Zzxqv Testperson', 'status' => 'active']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?search=Zzxqv')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Zzxqv Testperson');
});

it('filtre par rôle', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?role=user_pro')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->proUser->id);
});

it('filtre par statut', function () {
    User::factory()->create(['status' => 'suspended']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?status=suspended')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'suspended');
});

it('filtre les utilisateurs en ligne', function () {
    $online = User::factory()->create(['status' => 'active', 'last_seen_at' => now()->subMinutes(2)]);
    User::factory()->create(['status' => 'active', 'last_seen_at' => now()->subHours(2)]);

    // mibeko-dashboard#129 : cet appel authentifié en Bearer marque l'admin
    // lui-même « en ligne » (middleware `UpdateLastSeen` sur le groupe api).
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?online=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->admin->id, $online->id])->sort()->values()->all());
});

it('segmente l\'équipe interne', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users?segment=team')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->admin->id);
});

// ---------------------------------------------------------------------------
// Statistiques & fiche
// ---------------------------------------------------------------------------

it('expose les statistiques de population', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/users/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.active', 2)
        ->assertJsonStructure(['data' => ['total', 'active', 'suspended', 'pending', 'online', 'new_last_7_days', 'new_last_30_days']]);
});

it('retourne la fiche détaillée avec permissions effectives', function () {
    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/users/{$this->proUser->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->proUser->id)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'email', 'roles', 'permissions_direct', 'permissions_effective', 'active_tokens_count', 'dossiers_count', 'recent_audits'],
        ])
        ->assertJsonPath('data.roles', ['user_pro']);
});

// ---------------------------------------------------------------------------
// Création
// ---------------------------------------------------------------------------

it('crée un utilisateur avec un mot de passe fourni', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Nouvelle Recrue',
            'email' => 'recrue@mibeko.test',
            'password' => 'motdepasse123',
            'roles' => ['editor'],
            'mark_verified' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'recrue@mibeko.test')
        ->assertJsonPath('data.user.roles', ['editor'])
        ->assertJsonPath('data.generated_password', null);

    $created = User::where('email', 'recrue@mibeko.test')->first();
    expect($created)->not->toBeNull();
    expect($created->hasRole('editor'))->toBeTrue();
    expect($created->email_verified_at)->not->toBeNull();
});

it('génère un mot de passe quand aucun n\'est fourni', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Sans MDP',
            'email' => 'sansmdp@mibeko.test',
            'roles' => ['user_pro'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'sansmdp@mibeko.test')
        ->assertJson(fn ($json) => $json->where('data.generated_password', fn ($pwd) => is_string($pwd) && strlen($pwd) >= 12)->etc());
});

it('refuse un email déjà utilisé', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Doublon',
            'email' => $this->proUser->email,
            'roles' => ['user_pro'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

// ---------------------------------------------------------------------------
// Mise à jour : statut, rôles, permissions
// ---------------------------------------------------------------------------

it('suspend un utilisateur et révoque ses sessions', function () {
    $this->proUser->createToken('mobile');
    expect($this->proUser->tokens()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", [
            'status' => 'suspended',
            'suspension_reason' => 'Comportement abusif',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $this->proUser->refresh();
    expect($this->proUser->status)->toBe('suspended');
    expect($this->proUser->suspended_at)->not->toBeNull();
    expect($this->proUser->suspension_reason)->toBe('Comportement abusif');
    expect($this->proUser->tokens()->count())->toBe(0);
});

it('réactive un utilisateur et efface le motif de suspension', function () {
    $this->proUser->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'Test']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", ['status' => 'active'])
        ->assertOk();

    $this->proUser->refresh();
    expect($this->proUser->status)->toBe('active');
    expect($this->proUser->suspended_at)->toBeNull();
    expect($this->proUser->suspension_reason)->toBeNull();
});

it('synchronise les rôles', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", ['roles' => ['editor']])
        ->assertOk()
        ->assertJsonPath('data.roles', ['editor']);

    expect($this->proUser->fresh()->hasRole('editor'))->toBeTrue();
    expect($this->proUser->fresh()->hasRole('user_pro'))->toBeFalse();
});

it('attribue des permissions directes', function () {
    Permission::findOrCreate('documents.publish');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", ['permissions' => ['documents.publish']])
        ->assertOk();

    expect($this->proUser->fresh()->hasDirectPermission('documents.publish'))->toBeTrue();
});

it('trace la modification de statut dans le journal d\'audit', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->proUser->id}", ['status' => 'suspended'])
        ->assertOk();

    $this->assertDatabaseHas('audits', [
        'auditable_id' => $this->proUser->id,
        'auditable_type' => User::class,
        'event' => 'updated',
    ]);
});

// ---------------------------------------------------------------------------
// Garde-fous d'intégrité
// ---------------------------------------------------------------------------

it('empêche un admin de se suspendre lui-même', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->admin->id}", ['status' => 'suspended'])
        ->assertStatus(422);

    expect($this->admin->fresh()->status)->toBe('active');
});

it('empêche de retirer le rôle du dernier administrateur', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/users/{$this->admin->id}", ['roles' => ['editor']])
        ->assertStatus(409);

    expect($this->admin->fresh()->hasRole('admin'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Suppression / restauration
// ---------------------------------------------------------------------------

it('supprime (soft) un utilisateur', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->proUser->id}")
        ->assertOk();

    expect($this->proUser->fresh()->trashed())->toBeTrue();
});

it('empêche un admin de se supprimer lui-même', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->admin->id}")
        ->assertStatus(422);

    expect($this->admin->fresh()->trashed())->toBeFalse();
});

it('restaure un utilisateur supprimé', function () {
    $this->proUser->delete();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $this->proUser->id);

    expect($this->proUser->fresh()->trashed())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Actions de support
// ---------------------------------------------------------------------------

it('envoie un code de réinitialisation de mot de passe', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/password-reset")
        ->assertOk();

    Notification::assertSentTo($this->proUser, PasswordResetCodeNotification::class);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->proUser->email]);
});

it('révoque toutes les sessions d\'un utilisateur', function () {
    $this->proUser->createToken('a');
    $this->proUser->createToken('b');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/revoke-tokens")
        ->assertOk()
        ->assertJsonPath('data.revoked', 2);

    expect($this->proUser->tokens()->count())->toBe(0);
});

it('marque l\'email comme vérifié', function () {
    $user = User::factory()->unverified()->create(['status' => 'active']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->id}/verify-email")
        ->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

// mibeko-dashboard#203 : renvoi du lien de vérification depuis la fiche admin.

it('renvoie le lien de vérification et trace le renvoi', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['status' => 'active']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->id}/verification-email")
        ->assertStatus(202)
        ->assertJsonPath('data.remaining', 2);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    expect($user->fresh()->email_verified_at)->toBeNull();
    $this->assertDatabaseHas('audits', [
        'auditable_id' => $user->id,
        'auditable_type' => User::class,
        'event' => 'verification_email_resent',
        'user_id' => $this->admin->id,
    ]);
});

it('refuse de renvoyer le lien à une adresse déjà vérifiée', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/verification-email")
        ->assertStatus(409);

    Notification::assertNothingSent();
});

it('refuse de renvoyer le lien à un compte suspendu', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['status' => 'suspended']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->id}/verification-email")
        ->assertStatus(409);

    Notification::assertNothingSent();
});

it('limite les renvois à trois par heure pour un même compte', function () {
    // Le quota générique `api` tombe à 2/min en test : on le retire pour
    // n'exercer que celui du contrôleur, clé par compte destinataire.
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
    $user = User::factory()->unverified()->create(['status' => 'active']);
    $other = User::factory()->unverified()->create(['status' => 'active']);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$user->id}/verification-email")
            ->assertStatus(202);
    }

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$user->id}/verification-email")
        ->assertStatus(429);

    // Le quota vise le destinataire : un autre compte reste joignable.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$other->id}/verification-email")
        ->assertStatus(202);

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 3);
});

it('refuse le renvoi du lien à un non-admin', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['status' => 'active']);

    $this->actingAs($this->proUser)
        ->postJson("/api/v1/admin/users/{$user->id}/verification-email")
        ->assertForbidden();

    Notification::assertNothingSent();
});

it('indique dans la fiche si la vérification est exigée', function () {
    $user = User::factory()->unverified()->create([
        'status' => 'active',
        'email_verification_required' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.email_verification_required', true);
});

it('désactive la double authentification', function () {
    expect($this->proUser->two_factor_secret)->not->toBeNull();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->proUser->id}/two-factor")
        ->assertOk();

    $this->proUser->refresh();
    expect($this->proUser->two_factor_secret)->toBeNull();
    expect($this->proUser->two_factor_confirmed_at)->toBeNull();
});
