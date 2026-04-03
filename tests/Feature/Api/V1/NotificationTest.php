<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can list user notifications', function () {
    $user = User::factory()->create();

    // Create some notifications for the user
    $user->notifications()->create([
        'title' => 'Test',
        'message' => 'Test message',
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'test'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/notifications');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data');
});

it('can mark a notification as read', function () {
    $user = User::factory()->create();

    $notification = $user->notifications()->create([
        'title' => 'Test',
        'message' => 'Test message',
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'test'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->patchJson("/api/v1/notifications/{$notification->id}/read");

    $response->assertStatus(200);

    $this->assertNotNull($notification->fresh()->read_at);
});

it('can mark all notifications as read', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 3; $i++) {
        $user->notifications()->create([
            'title' => 'Test',
            'message' => 'Test message',
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'test'],
            'read_at' => null,
        ]);
    }

    $response = $this->actingAs($user)->postJson('/api/v1/notifications/read-all');

    $response->assertStatus(200);

    $this->assertEquals(0, $user->unreadNotifications()->count());
});

it('can delete a notification', function () {
    $user = User::factory()->create();

    $notification = $user->notifications()->create([
        'title' => 'Test',
        'message' => 'Test message',
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'test'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/v1/notifications/{$notification->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('notifications', [
        'id' => $notification->id,
    ]);
});
