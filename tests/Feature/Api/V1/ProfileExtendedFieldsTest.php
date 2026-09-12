<?php

use App\Models\MobileProfile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * mibeko-dashboard#135 : cadre d'usage, métier, intérêts — PATCH strictement
 * partiel, mapping documenté profession ↔ usage_context, et protections
 * contre l'écrasement croisé web/mobile.
 */
it('met à jour uniquement usage_context sans toucher au reste du profil déjà renseigné', function () {
    $user = User::factory()->create();
    $user->mobileProfile()->create(['phone' => '+242068000000', 'company' => 'Cabinet Mibeko']);

    $this->actingAs($user)->putJson('/api/v1/profile', ['usage_context' => 'professional'])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.usage_context', 'professional')
        ->assertJsonPath('data.profile.phone', '+242068000000')
        ->assertJsonPath('data.profile.company', 'Cabinet Mibeko');
});

it('refuse un usage_context hors catalogue sans perdre les autres champs', function () {
    $user = User::factory()->create();
    $user->mobileProfile()->create(['phone' => '+242068000000']);

    $this->actingAs($user)->putJson('/api/v1/profile', ['usage_context' => 'invalide'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('usage_context');

    $this->actingAs($user)->getJson('/api/v1/profile')
        ->assertJsonPath('data.profile.phone', '+242068000000')
        ->assertJsonPath('data.profile.usage_context', null);
});

it('dérive usage_context depuis profession quand seul profession est envoyé', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['profession' => 'Professionnel du droit'])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.profession', 'Professionnel du droit')
        ->assertJsonPath('data.profile.usage_context', 'professional');
});

it('dérive profession depuis usage_context quand seul usage_context est envoyé', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['usage_context' => 'studies'])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.usage_context', 'studies')
        ->assertJsonPath('data.profile.profession', 'Étudiant');
});

it('ne dérive rien quand profession et usage_context sont tous deux fournis', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', [
        'profession' => 'Citoyen',
        'usage_context' => 'professional',
    ])->assertStatus(200)
        ->assertJsonPath('data.profile.profession', 'Citoyen')
        ->assertJsonPath('data.profile.usage_context', 'professional');
});

it('une profession choisie sur mobile conserve un téléphone et une organisation renseignés sur le web', function () {
    $user = User::factory()->create();
    // Simule le web : téléphone + organisation déjà saisis.
    $this->actingAs($user)->putJson('/api/v1/profile', [
        'phone' => '+242068000000',
        'company' => 'Cabinet Mibeko',
    ])->assertStatus(200);

    // Simule le mobile corrigé (#135) : seul `profession` est envoyé, phone/company omis.
    $this->actingAs($user)->putJson('/api/v1/profile', ['profession' => 'Professionnel du droit'])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.phone', '+242068000000')
        ->assertJsonPath('data.profile.company', 'Cabinet Mibeko')
        ->assertJsonPath('data.profile.usage_context', 'professional');
});

it('efface explicitement job_title et phone via null sans toucher aux autres champs', function () {
    $user = User::factory()->create();
    $user->mobileProfile()->create(['phone' => '+242068000000', 'job_title' => 'Avocat', 'company' => 'Cabinet Mibeko']);

    $this->actingAs($user)->putJson('/api/v1/profile', ['phone' => null, 'job_title' => null])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.phone', null)
        ->assertJsonPath('data.profile.job_title', null)
        ->assertJsonPath('data.profile.company', 'Cabinet Mibeko');
});

it('synchronise les intérêts à partir de slugs de tags existants', function () {
    $user = User::factory()->create();
    $famille = Tag::create(['name' => 'Famille', 'slug' => 'famille']);
    $travail = Tag::create(['name' => 'Travail', 'slug' => 'travail']);

    $this->actingAs($user)->putJson('/api/v1/profile', ['interests' => [$famille->slug, $travail->slug]])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.interests', [$famille->slug, $travail->slug]);
});

it('efface explicitement les intérêts via un tableau vide, distinct de l\'omission', function () {
    $user = User::factory()->create();
    $tag = Tag::create(['name' => 'Famille', 'slug' => 'famille']);
    $user->tags()->sync([$tag->id]);

    $this->actingAs($user)->putJson('/api/v1/profile', ['interests' => []])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.interests', []);
});

it('omettre interests laisse les tags inchangés', function () {
    $user = User::factory()->create();
    $tag = Tag::create(['name' => 'Famille', 'slug' => 'famille']);
    $user->tags()->sync([$tag->id]);

    $this->actingAs($user)->putJson('/api/v1/profile', ['name' => 'Nouveau Nom'])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.interests', [$tag->slug]);
});

it('refuse un slug d\'intérêt inconnu', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['interests' => ['inconnu']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('interests.0');
});

it('choisir professionnel n\'accorde aucun rôle ni entitlement Pro', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('mobile_user'));

    $before = $this->actingAs($user)->getJson('/api/v1/me/entitlements')->json('data.plan');

    $this->actingAs($user)->putJson('/api/v1/profile', ['usage_context' => 'professional'])
        ->assertStatus(200);

    $this->actingAs($user)->getJson('/api/v1/me/entitlements')
        ->assertJsonPath('data.plan', $before);
});

it('un compte sans mobile_profiles répond correctement en lecture', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/profile')
        ->assertStatus(200)
        ->assertJsonPath('data.profile.usage_context', null)
        ->assertJsonPath('data.profile.job_title', null)
        ->assertJsonPath('data.profile.interests', []);
});

it('une ligne existante avec seulement profession renseigné garde usage_context à null sans appel API', function () {
    $user = User::factory()->create();
    MobileProfile::create(['user_id' => $user->id, 'profession' => 'Citoyen']);

    $this->actingAs($user)->getJson('/api/v1/profile')
        ->assertJsonPath('data.profile.profession', 'Citoyen')
        ->assertJsonPath('data.profile.usage_context', null);
});

it('accepte des numéros de téléphone internationaux', function (string $phone) {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['phone' => $phone])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.phone', $phone);
})->with([
    '+242 06 800 00 00',
    '+33612345678',
    '+1 202-555-0143',
]);

it('refuse un téléphone manifestement invalide', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['phone' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('efface le téléphone via une chaîne vide', function () {
    $user = User::factory()->create();
    $user->mobileProfile()->create(['phone' => '+242068000000']);

    // Le middleware global `ConvertEmptyStringsToNull` normalise `''` en
    // `null` avant validation — une chaîne vide efface donc bien le champ.
    $this->actingAs($user)->putJson('/api/v1/profile', ['phone' => ''])
        ->assertStatus(200)
        ->assertJsonPath('data.profile.phone', null);
});

it('écrire phone et usage_context ne touche jamais les consentements existants', function () {
    $user = User::factory()->create();
    $user->settingsOrCreate()->update(['marketing_consent' => true, 'analytics_consent' => false]);

    $this->actingAs($user)->putJson('/api/v1/profile', [
        'phone' => '+242068000000',
        'usage_context' => 'professional',
    ])->assertStatus(200);

    $settings = $user->settingsOrCreate()->fresh();
    expect($settings->marketing_consent)->toBeTrue()
        ->and($settings->analytics_consent)->toBeFalse();
});
