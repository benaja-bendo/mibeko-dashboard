<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

it('can display audits index page', function () {
    $user = User::factory()->create();
    
    Audit::create([
        'user_id' => $user->id,
        'user_type' => User::class,
        'event' => 'created',
        'auditable_type' => 'App\Models\LegalDocument',
        'auditable_id' => \Illuminate\Support\Str::uuid()->toString(),
        'old_values' => [],
        'new_values' => ['title' => 'Test'],
        'url' => 'http://localhost/test',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test Agent',
    ]);
    
    $response = $this->actingAs($user)->get('/auditing');

    $response->assertStatus(200);
});
