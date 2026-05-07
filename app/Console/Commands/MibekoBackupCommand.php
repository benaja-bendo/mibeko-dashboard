<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MibekoBackupCommand extends Command
{
    protected $signature = 'mibeko:backup
                            {--disk= : Disk de destination (ex: local, s3, gdrive)}
                            {--only-db : Sauvegarde uniquement la base de données}
                            {--only-files : Sauvegarde uniquement les fichiers}
                            {--clean : Lance backup:clean après la sauvegarde}';

    protected $description = 'Lance une sauvegarde Spatie avec des options simplifiées.';

    public function handle(): int
    {
        $disk = $this->option('disk');
        $onlyDb = (bool) $this->option('only-db');
        $onlyFiles = (bool) $this->option('only-files');
        $clean = (bool) $this->option('clean');
        $backupDirectory = (string) config('backup.backup.name', '');

        if ($onlyDb && $onlyFiles) {
            $this->error('Choisir soit --only-db soit --only-files, pas les deux.');

            return self::FAILURE;
        }

        $requestedDisk = null;
        if (is_string($disk) && $disk !== '') {
            $requestedDisk = trim($disk);

            if ($requestedDisk === '') {
                $this->error('Option --disk invalide.');

                return self::FAILURE;
            }
        }

        $targetDisks = $requestedDisk !== null
            ? [$requestedDisk]
            : (array) config('backup.backup.destination.disks', []);

        foreach ($targetDisks as $targetDisk) {
            if (! is_string($targetDisk) || trim($targetDisk) === '') {
                continue;
            }

            $targetDisk = trim($targetDisk);

            $filesystemDiskConfig = config("filesystems.disks.{$targetDisk}");
            if (! is_array($filesystemDiskConfig)) {
                $this->error("Le disk '{$targetDisk}' n'existe pas dans config/filesystems.php.");

                return self::FAILURE;
            }

            if ($requestedDisk !== null) {
                $configuredBackupDisks = config('backup.backup.destination.disks', []);
                if (! is_array($configuredBackupDisks)) {
                    $configuredBackupDisks = [];
                }

                if (! in_array($targetDisk, $configuredBackupDisks, true)) {
                    $configuredBackupDisks[] = $targetDisk;
                    config(['backup.backup.destination.disks' => $configuredBackupDisks]);
                }
            }

            if ($backupDirectory === '') {
                continue;
            }

            try {
                Storage::disk($targetDisk)->makeDirectory($backupDirectory);
            } catch (Throwable $e) {
                $this->error("Impossible de préparer le dossier de sauvegarde sur le disk '{$targetDisk}'.");
                $this->line($e->getMessage());

                return self::FAILURE;
            }
        }

        $args = [];

        if ($requestedDisk !== null) {
            $args['--only-to-disk'] = $requestedDisk;
        }

        if ($onlyDb) {
            $args['--only-db'] = true;
        }

        if ($onlyFiles) {
            $args['--only-files'] = true;
        }

        $exitCode = Artisan::call('backup:run', $args);
        $this->output->write(Artisan::output());

        if ($exitCode !== 0) {
            return $exitCode;
        }

        if ($clean) {
            $cleanExitCode = Artisan::call('backup:clean');
            $this->output->write(Artisan::output());

            return $cleanExitCode;
        }

        return self::SUCCESS;
    }
}
