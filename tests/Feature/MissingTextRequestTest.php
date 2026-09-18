<?php

use App\Models\CurationFlag;
use App\Models\User;

/**
 * mibeko-front#34 : demander un texte absent du catalogue — réutilise
 * `curation_flags` (source=report) sans cible, pour que l'utilisateur
 * retrouve sa demande via `mine()`. Distinct de l'endpoint public `/reports`
 * (mobile, anonyme, toujours ciblé sur un document/article existant).
 */
it('refuse la demande sans authentification', function () {
    $this->postJson('/api/v1/library/missing-text-requests', ['description' => 'Le code minier'])
        ->assertUnauthorized();
});

it('crée une demande sans cible ni auteur pour un texte manquant', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/library/missing-text-requests', ['description' => 'Le code minier'])
        ->assertStatus(201);

    $flag = CurationFlag::sole();

    expect($flag->type_probleme)->toBe(CurationFlag::TYPE_TEXTE_MANQUANT);
    expect($flag->source)->toBe(CurationFlag::SOURCE_REPORT);
    expect($flag->severity)->toBe(CurationFlag::SEVERITY_INFO);
    expect($flag->document_id)->toBeNull();
    expect($flag->article_id)->toBeNull();
    expect($flag->created_by)->toBe($user->id);
    expect($flag->resolved)->toBeFalse();
    expect($response->json('data.description'))->toBe('Le code minier');
});

it('exige une description', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/library/missing-text-requests', [])
        ->assertStatus(422);
});

it('ne retourne que les demandes de l\'utilisateur courant', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->postJson('/api/v1/library/missing-text-requests', ['description' => 'Code minier'])
        ->assertStatus(201);
    $this->actingAs($userB)->postJson('/api/v1/library/missing-text-requests', ['description' => 'Code forestier'])
        ->assertStatus(201);

    $response = $this->actingAs($userA)->getJson('/api/v1/library/missing-text-requests')->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['description'])->toBe('Code minier');
});

it('n\'affecte pas l\'endpoint public /reports', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/library/missing-text-requests', ['description' => 'Code minier'])
        ->assertStatus(201);

    $this->postJson('/api/v1/reports', ['type_probleme' => 'contenu_errone'])
        ->assertStatus(422); // toujours sans authentification et sans cible : comportement inchangé
});
