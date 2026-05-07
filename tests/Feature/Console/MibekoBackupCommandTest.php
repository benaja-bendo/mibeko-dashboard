<?php

use App\Console\Commands\MibekoBackupCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

function runMibekoBackupCommand(array $input): array
{
    $command = app(MibekoBackupCommand::class);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $exitCode = $tester->execute($input);

    return [$exitCode, $tester->getDisplay()];
}

it('échoue si le disk demandé n’existe pas dans filesystems', function () {
    config(['backup.backup.name' => 'TestApp']);
    config(['filesystems.disks' => []]);

    Artisan::shouldReceive('call')->never();
    Artisan::shouldReceive('output')->andReturn('');

    [$exitCode, $output] = runMibekoBackupCommand([
        '--disk' => 'gdrive',
        '--only-db' => true,
    ]);

    expect($exitCode)->toBe(1);
    expect($output)->toContain("Le disk 'gdrive' n'existe pas");
});

it('ajoute le disk demandé aux destinations de backup à l’exécution', function () {
    config([
        'backup.backup.name' => 'TestApp',
        'filesystems.disks.gdrive' => [
            'driver' => 'gdrive',
        ],
        'backup.backup.destination.disks' => ['local'],
    ]);

    $disk = Mockery::mock();
    $disk->shouldReceive('makeDirectory')->once()->with('TestApp')->andReturnTrue();
    Storage::shouldReceive('disk')->once()->with('gdrive')->andReturn($disk);

    Artisan::shouldReceive('call')
        ->once()
        ->with('backup:run', Mockery::on(function (array $args) {
            expect($args)->toHaveKey('--only-to-disk', 'gdrive');
            expect($args)->toHaveKey('--only-db', true);

            return true;
        }))
        ->andReturn(0);

    Artisan::shouldReceive('output')->andReturn('');

    [$exitCode, $output] = runMibekoBackupCommand([
        '--disk' => 'gdrive',
        '--only-db' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect($output)->toBeString();

    expect(config('backup.backup.destination.disks'))->toContain('gdrive');
});

it('prépare les dossiers de sauvegarde sur tous les disks configurés quand aucun disk n’est fourni', function () {
    config([
        'backup.backup.name' => 'TestApp',
        'backup.backup.destination.disks' => ['local', 'gdrive'],
        'filesystems.disks.local' => [
            'driver' => 'local',
        ],
        'filesystems.disks.gdrive' => [
            'driver' => 'gdrive',
        ],
    ]);

    $localDisk = Mockery::mock();
    $localDisk->shouldReceive('makeDirectory')->once()->with('TestApp')->andReturnTrue();

    $gdriveDisk = Mockery::mock();
    $gdriveDisk->shouldReceive('makeDirectory')->once()->with('TestApp')->andReturnTrue();

    Storage::shouldReceive('disk')->once()->with('local')->andReturn($localDisk);
    Storage::shouldReceive('disk')->once()->with('gdrive')->andReturn($gdriveDisk);

    Artisan::shouldReceive('call')->once()->with('backup:run', [])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    [$exitCode] = runMibekoBackupCommand([]);

    expect($exitCode)->toBe(0);
});
