<?php

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
});

// ---------------------------------------------------------------------------
// Création & envoi
// ---------------------------------------------------------------------------

it('refuse l\'invitation à un non-admin', function () {
    $this->actingAs($this->proUser)
        ->postJson('/api/v1/admin/invitations', ['email' => 'x@y.test', 'roles' => ['editor']])
        ->assertForbidden();
});

it('crée et envoie une invitation', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', [
            'email' => 'collegue@mibeko.test',
            'roles' => ['editor'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'collegue@mibeko.test')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('user_invitations', ['email' => 'collegue@mibeko.test']);
    Notification::assertSentOnDemand(UserInvitationNotification::class);
});

it('refuse d\'inviter une adresse déjà enregistrée', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', [
            'email' => $this->proUser->email,
            'roles' => ['editor'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('refuse une seconde invitation en attente pour la même adresse', function () {
    UserInvitation::create([
        'email' => 'collegue@mibeko.test',
        'token' => Hash::make('tok'),
        'roles' => ['editor'],
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/invitations', [
            'email' => 'collegue@mibeko.test',
            'roles' => ['editor'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

// ---------------------------------------------------------------------------
// Liste / annulation / renvoi
// ---------------------------------------------------------------------------

it('liste les invitations', function () {
    UserInvitation::create([
        'email' => 'a@mibeko.test',
        'token' => Hash::make('tok'),
        'roles' => ['editor'],
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'a@mibeko.test');
});

it('annule une invitation', function () {
    $invitation = UserInvitation::create([
        'email' => 'a@mibeko.test',
        'token' => Hash::make('tok'),
        'roles' => ['editor'],
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/invitations/{$invitation->id}")
        ->assertOk();

    $this->assertDatabaseMissing('user_invitations', ['id' => $invitation->id]);
});

it('renvoie une invitation avec un nouveau token', function () {
    Notification::fake();

    $invitation = UserInvitation::create([
        'email' => 'a@mibeko.test',
        'token' => Hash::make('ancien'),
        'roles' => ['editor'],
        'expires_at' => now()->addDay(),
    ]);
    $oldToken = $invitation->token;

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/invitations/{$invitation->id}/resend")
        ->assertOk();

    expect($invitation->fresh()->token)->not->toBe($oldToken);
    Notification::assertSentOnDemand(UserInvitationNotification::class);
});

// ---------------------------------------------------------------------------
// Acceptation publique
// ---------------------------------------------------------------------------

it('accepte une invitation et crée le compte avec les bons rôles', function () {
    $invitation = UserInvitation::create([
        'email' => 'recrue@mibeko.test',
        'token' => Hash::make('le-vrai-token'),
        'roles' => ['editor'],
        'expires_at' => now()->addDays(7),
    ]);

    $this->postJson('/api/v1/invitations/accept', [
        'email' => 'recrue@mibeko.test',
        'token' => 'le-vrai-token',
        'name' => 'Nouvelle Recrue',
        'password' => 'motdepasse123',
        'password_confirmation' => 'motdepasse123',
        'device_name' => 'web',
    ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'roles']]])
        ->assertJsonPath('data.user.roles', ['editor']);

    $created = User::where('email', 'recrue@mibeko.test')->first();
    expect($created)->not->toBeNull();
    expect($created->hasRole('editor'))->toBeTrue();
    expect($created->email_verified_at)->not->toBeNull();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('refuse un token d\'invitation invalide', function () {
    UserInvitation::create([
        'email' => 'recrue@mibeko.test',
        'token' => Hash::make('le-vrai-token'),
        'roles' => ['editor'],
        'expires_at' => now()->addDays(7),
    ]);

    $this->postJson('/api/v1/invitations/accept', [
        'email' => 'recrue@mibeko.test',
        'token' => 'mauvais-token',
        'name' => 'Pirate',
        'password' => 'motdepasse123',
        'password_confirmation' => 'motdepasse123',
    ])->assertStatus(422);

    expect(User::where('email', 'recrue@mibeko.test')->exists())->toBeFalse();
});

it('refuse une invitation expirée', function () {
    UserInvitation::create([
        'email' => 'recrue@mibeko.test',
        'token' => Hash::make('le-vrai-token'),
        'roles' => ['editor'],
        'expires_at' => now()->subDay(),
    ]);

    $this->postJson('/api/v1/invitations/accept', [
        'email' => 'recrue@mibeko.test',
        'token' => 'le-vrai-token',
        'name' => 'Retardataire',
        'password' => 'motdepasse123',
        'password_confirmation' => 'motdepasse123',
    ])->assertStatus(422);
});
