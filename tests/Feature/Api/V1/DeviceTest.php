<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can register a device', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/devices/register', [
        'device_id' => '12345',
        'push_token' => 'token_abc',
        'platform' => 'android',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.device_id', '12345')
        ->assertJsonPath('data.platform', 'android');

    $this->assertDatabaseHas('devices', [
        'device_id' => '12345',
        'push_token' => 'token_abc',
        'platform' => 'android',
        'status' => 'active',
    ]);
});

it('can update an existing device token', function () {
    $user = User::factory()->create();

    Device::create([
        'device_id' => '12345',
        'push_token' => 'old_token',
        'platform' => 'android',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/devices/register', [
        'device_id' => '12345',
        'push_token' => 'new_token',
        'platform' => 'android',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('devices', [
        'device_id' => '12345',
        'push_token' => 'new_token',
    ]);
});

it('can unregister a device', function () {
    $user = User::factory()->create();

    Device::create([
        'device_id' => '12345',
        'push_token' => 'some_token',
        'platform' => 'android',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/devices/unregister', [
        'device_id' => '12345',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('devices', [
        'device_id' => '12345',
        'status' => 'inactive',
    ]);
});

it('enregistre un appareil invité sans propriétaire', function () {
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'invite-1',
        'push_token' => 'fcm_invite',
        'platform' => 'android',
    ])->assertOk();

    expect(Device::where('device_id', 'invite-1')->first()->user_id)->toBeNull();
});

it('rattache l\'appareil à l\'utilisateur quand un jeton est présent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-1',
        'push_token' => 'fcm_phone_1',
        'platform' => 'ios',
    ])->assertOk();

    expect(Device::where('device_id', 'phone-1')->first()->user_id)->toBe($user->id);
});

it('ne crée qu\'une seule ligne quand le même appareil se ré-enregistre, et met à jour son propriétaire', function () {
    $user = User::factory()->create();

    // Premier enregistrement : mode invité (avant connexion).
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-2',
        'push_token' => 'fcm_old',
        'platform' => 'android',
    ])->assertOk();

    // Second enregistrement : l'utilisateur s'est connecté entre-temps.
    $this->actingAs($user)->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-2',
        'push_token' => 'fcm_new',
        'platform' => 'android',
    ])->assertOk();

    expect(Device::where('device_id', 'phone-2')->count())->toBe(1);

    $device = Device::where('device_id', 'phone-2')->first();
    expect($device->user_id)->toBe($user->id)
        ->and($device->push_token)->toBe('fcm_new');
});

it('ne détache pas un appareil déjà rattaché lors d\'un ré-enregistrement anonyme', function () {
    $user = User::factory()->create();
    $device = Device::factory()->ownedBy($user)->create(['device_id' => 'phone-3']);

    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-3',
        'push_token' => 'fcm_rotated',
        'platform' => $device->platform,
    ])->assertOk();

    expect($device->fresh()->user_id)->toBe($user->id);
});

it('interdit à un utilisateur de désinscrire l\'appareil d\'un autre', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $device = Device::factory()->ownedBy($owner)->create(['device_id' => 'phone-4']);

    $this->actingAs($intruder)->postJson('/api/v1/devices/unregister', [
        'device_id' => 'phone-4',
    ])->assertStatus(404);

    expect($device->fresh()->status)->toBe('active');
});

it('laisse un invité désinscrire un appareil sans propriétaire', function () {
    $device = Device::factory()->create(['device_id' => 'phone-5']);

    $this->postJson('/api/v1/devices/unregister', [
        'device_id' => 'phone-5',
    ])->assertOk();

    expect($device->fresh()->status)->toBe('inactive');
});

/*
|--------------------------------------------------------------------------
| Version de l'app installée (choix de la forme du push)
|--------------------------------------------------------------------------
*/

it('enregistre la version de l\'app quand elle est fournie', function () {
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-version',
        'push_token' => 'fcm_version',
        'platform' => 'android',
        'app_version' => '1.2.0',
    ])->assertOk()
        ->assertJsonPath('data.app_version', '1.2.0');

    expect(Device::where('device_id', 'phone-version')->first()->app_version)->toBe('1.2.0');
});

it('accepte un enregistrement sans version d\'app', function () {
    // Contrat de l'app PUBLIÉE (v1.0/v1.1) : elle n'envoie pas ce champ, et son
    // enregistrement ne doit surtout pas se mettre à échouer.
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-sans-version',
        'push_token' => 'fcm_sans_version',
        'platform' => 'android',
    ])->assertOk();

    expect(Device::where('device_id', 'phone-sans-version')->first()->app_version)->toBeNull();
});

it('refuse une version d\'app illisible', function () {
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'phone-version-ko',
        'push_token' => 'fcm_version_ko',
        'platform' => 'android',
        'app_version' => 'derniere-en-date',
    ])->assertStatus(422);

    expect(Device::where('device_id', 'phone-version-ko')->exists())->toBeFalse();
});

it('oublie la version quand un ré-enregistrement ne l\'annonce plus', function () {
    $payload = [
        'device_id' => 'phone-downgrade',
        'push_token' => 'fcm_downgrade',
        'platform' => 'android',
    ];

    $this->postJson('/api/v1/devices/register', $payload + ['app_version' => '1.2.0'])->assertOk();

    // Réinstallation en version antérieure : conserver « 1.2.0 » ferait envoyer
    // à cet appareil un push data-only qu'il ne saurait pas afficher.
    $this->postJson('/api/v1/devices/register', $payload)->assertOk();

    expect(Device::where('device_id', 'phone-downgrade')->first()->app_version)->toBeNull();
});

it('applique le quota dédié à l\'enregistrement d\'appareil', function () {
    $payload = [
        'device_id' => 'phone-spam',
        'push_token' => 'fcm_spam',
        'platform' => 'android',
    ];

    // Le quota générique `api` (2/min en test) est explicitement remplacé :
    // 5 enregistrements du même appareil passent, le 6e est refusé.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/devices/register', $payload)->assertOk();
    }

    $this->postJson('/api/v1/devices/register', $payload)->assertStatus(429);
});
