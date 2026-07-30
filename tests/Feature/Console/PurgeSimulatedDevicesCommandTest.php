<?php

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ne touche à rien sans --force (simulation par défaut)', function () {
    $simulated = Device::factory()->simulated()->create();
    $real = Device::factory()->create();

    $this->artisan('mibeko:purge-simulated-devices')
        ->expectsOutputToContain('SIMULATION')
        ->assertExitCode(0);

    expect($simulated->fresh()->status)->toBe('active')
        ->and($real->fresh()->status)->toBe('active');
});

it('désactive les appareils simulés avec --force', function () {
    $simulated = Device::factory()->simulated()->count(3)->create();
    $real = Device::factory()->create();

    $this->artisan('mibeko:purge-simulated-devices --force')
        ->assertExitCode(0);

    expect(Device::whereIn('id', $simulated->pluck('id'))->where('status', 'inactive')->count())->toBe(3)
        ->and($real->fresh()->status)->toBe('active');
});

it('respecte --dry-run même en présence de --force', function () {
    $simulated = Device::factory()->simulated()->create();

    $this->artisan('mibeko:purge-simulated-devices --force --dry-run')
        ->expectsOutputToContain('SIMULATION')
        ->assertExitCode(0);

    expect($simulated->fresh()->status)->toBe('active');
});

it('signale l\'absence d\'appareil simulé', function () {
    Device::factory()->create();

    $this->artisan('mibeko:purge-simulated-devices --force')
        ->expectsOutputToContain('Aucun appareil simulé actif')
        ->assertExitCode(0);
});

it('ignore les appareils simulés déjà inactifs', function () {
    Device::factory()->simulated()->inactive()->create();

    $this->artisan('mibeko:purge-simulated-devices --force')
        ->expectsOutputToContain('Aucun appareil simulé actif')
        ->assertExitCode(0);
});
