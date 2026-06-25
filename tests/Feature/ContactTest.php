<?php

use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Couvre le formulaire de contact public (`POST /api/v1/contact`) : persistance
 * en base, relais e-mail, et validation.
 */
it('enregistre un message valide et tente le relais e-mail', function () {
    Mail::fake();

    $payload = [
        'name' => 'Awa Mabiala',
        'email' => 'awa@example.cg',
        'profile' => 'citoyen',
        'message' => 'Bonjour, comment obtenir un acte de naissance ?',
    ];

    $this->postJson('/api/v1/contact', $payload)
        ->assertStatus(201)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('contact_messages', [
        'email' => 'awa@example.cg',
        'profile' => 'citoyen',
        'handled' => false,
    ]);

    expect(ContactMessage::count())->toBe(1);
});

it('rejette un message incomplet', function () {
    $this->postJson('/api/v1/contact', ['name' => 'Sans email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'message']);
});

it('rejette un message trop court', function () {
    $this->postJson('/api/v1/contact', [
        'name' => 'Test',
        'email' => 'test@example.cg',
        'message' => 'court',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['message']);
});

it('rejette un profil non autorisé', function () {
    $this->postJson('/api/v1/contact', [
        'name' => 'Test',
        'email' => 'test@example.cg',
        'profile' => 'pirate',
        'message' => 'Un message suffisamment long pour passer.',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['profile']);
});
