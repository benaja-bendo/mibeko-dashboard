<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('refuse l\'impersonation à un non-admin', function () {
    $this->actingAs($this->proUser)
        ->postJson("/api/v1/admin/users/{$this->admin->id}/impersonate")
        ->assertForbidden();
});

it('permet à un admin d\'incarner un utilisateur', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/impersonate")
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'roles', 'permissions']]])
        ->assertJsonPath('data.user.id', $this->proUser->id);

    expect($this->proUser->tokens()->where('name', 'impersonation:'.$this->admin->id)->exists())->toBeTrue();
});

it('trace l\'impersonation dans le journal d\'audit', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/impersonate")
        ->assertOk();

    $this->assertDatabaseHas('audits', [
        'auditable_id' => $this->proUser->id,
        'auditable_type' => User::class,
        'event' => 'impersonation_started',
    ]);
});

it('interdit d\'incarner un autre administrateur', function () {
    $otherAdmin = User::factory()->create(['status' => 'active']);
    $otherAdmin->assignRole('admin');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$otherAdmin->id}/impersonate")
        ->assertForbidden();
});

it('interdit de s\'incarner soi-même', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->admin->id}/impersonate")
        ->assertStatus(422);
});

it('interdit d\'incarner un compte non actif', function () {
    $this->proUser->update(['status' => 'suspended']);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->proUser->id}/impersonate")
        ->assertStatus(422);
});
