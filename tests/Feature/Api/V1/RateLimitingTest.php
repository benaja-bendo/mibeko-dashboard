<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the api rate limiter and returns correct headers', function () {
    $response = $this->getJson('/api/v1/home');

    $response->assertStatus(200);
    $response->assertHeader('X-RateLimit-Limit', 2); // In testing it's 2
    $response->assertHeader('X-RateLimit-Remaining', 1);
});

it('throttles requests when limit is exceeded', function () {
    // Request 1: OK
    $this->getJson('/api/v1/home')->assertStatus(200);

    // Request 2: OK
    $this->getJson('/api/v1/home')->assertStatus(200);

    // Request 3: Throttled (429)
    $response = $this->getJson('/api/v1/home');
    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
});
