<?php

use App\Models\PlanGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->titulaire = User::factory()->create();
    $this->grant = PlanGrant::factory()->for($this->titulaire)->create();
});

it('laisse le titulaire télécharger son justificatif', function () {
    $this->actingAs($this->titulaire)
        ->getJson("/api/v1/billing/manual-grants/{$this->grant->id}/receipt")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it("refuse le justificatif d'un octroi appartenant à un autre compte", function () {
    $autre = User::factory()->create();

    $this->actingAs($autre)
        ->getJson("/api/v1/billing/manual-grants/{$this->grant->id}/receipt")
        ->assertNotFound();
});

it('exige une authentification pour télécharger un justificatif', function () {
    $this->getJson("/api/v1/billing/manual-grants/{$this->grant->id}/receipt")
        ->assertUnauthorized();
});

it("laisse l'admin télécharger le justificatif de n'importe quel titulaire", function () {
    Role::findOrCreate('admin');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/billing/grants/{$this->grant->id}/receipt")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('refuse le justificatif à un compte standard côté admin', function () {
    $standard = User::factory()->create();

    $this->actingAs($standard)
        ->getJson("/api/v1/admin/billing/grants/{$this->grant->id}/receipt")
        ->assertForbidden();
});
