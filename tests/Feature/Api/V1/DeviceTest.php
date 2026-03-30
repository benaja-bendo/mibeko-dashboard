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
