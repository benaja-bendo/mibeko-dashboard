<?php

use App\Models\Device;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * La migration `add_user_and_unique_constraint_to_devices_table` doit dédoublonner
 * avant de poser la contrainte UNIQUE, sans quoi elle échoue sur toute base
 * alimentée par l'ancien `updateOrCreate` non protégé (dev comme production).
 */
function deviceMigration(): object
{
    return require database_path('migrations/2026_07_30_153610_add_user_and_unique_constraint_to_devices_table.php');
}

it('conserve l\'appareil le plus récemment enregistré et retire les doublons', function () {
    // La contrainte est déjà en place dans la base de test : on la retire le
    // temps de recréer la situation d'avant migration (rollback transactionnel).
    Schema::table('devices', fn (Blueprint $table) => $table->dropUnique(['device_id']));

    $stale = Device::factory()->create([
        'device_id' => 'doublon',
        'push_token' => 'jeton_perime',
        'last_registered_at' => now()->subDays(3),
    ]);
    $recent = Device::factory()->create([
        'device_id' => 'doublon',
        'push_token' => 'jeton_valide',
        'last_registered_at' => now(),
    ]);
    $untouched = Device::factory()->create(['device_id' => 'unique']);

    deviceMigration()->deduplicateDeviceIds();

    expect(Device::where('device_id', 'doublon')->pluck('id')->all())->toBe([$recent->id])
        ->and(Device::find($stale->id))->toBeNull()
        ->and(Device::find($untouched->id))->not->toBeNull();
});

it('départage sans erreur des doublons dépourvus de date d\'enregistrement', function () {
    Schema::table('devices', fn (Blueprint $table) => $table->dropUnique(['device_id']));

    Device::factory()->count(2)->create([
        'device_id' => 'sans-date',
        'last_registered_at' => null,
    ]);

    deviceMigration()->deduplicateDeviceIds();

    expect(Device::where('device_id', 'sans-date')->count())->toBe(1);
});
