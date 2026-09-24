<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * `backup:clean` avec la stratégie bornée du 24/09/2026 (config/backup.php) :
 * rien au-delà de 51 jours, et surtout pas de nettoyage excessif — les zéros
 * de mensuel/annuel ne doivent pas vider les sauvegardes récentes.
 */
it('ne garde aucune sauvegarde de plus de 51 jours et garde toutes celles du dernier mois', function () {
    Storage::fake('gdrive');
    // Seul le disque de la sauvegarde planifiée : `s3`, actif par défaut dans
    // la config, sortirait du test vers un vrai stockage.
    config(['backup.backup.destination.disks' => ['gdrive']]);
    $dossier = config('backup.backup.name');

    // Une sauvegarde par nuit à 03:00 pendant 120 jours, comme le planificateur.
    foreach (range(0, 120) as $jours) {
        Storage::disk('gdrive')->put($dossier.'/'.now()->subDays($jours)->setTime(3, 0)->format('Y-m-d-H-i-s').'.zip', 'sauvegarde');
    }

    // `--config` : la commande relit la configuration au moment de tourner.
    // Sans lui, elle garde celle qu'on lui a injectée à l'enregistrement
    // d'Artisan (dès le `migrate:fresh` de RefreshDatabase), et les
    // surcharges ci-dessus seraient ignorées.
    $this->artisan('backup:clean', ['--disable-notifications' => true, '--config' => 'backup'])->assertSuccessful();

    $ages = collect(Storage::disk('gdrive')->files($dossier))
        ->map(fn (string $fichier) => (int) Carbon::createFromFormat('Y-m-d-H-i-s', basename($fichier, '.zip'))->diffInDays(now(), true));

    expect($ages->max())->toBeLessThanOrEqual(51)
        // Tout est gardé sur les 23 derniers jours (7 j complets + 16 quotidiens)…
        ->and($ages->filter(fn (int $age) => $age < 23)->count())->toBe(23)
        // … puis une par semaine jusqu'à 51 jours : pas de trou avant la borne.
        ->and($ages->filter(fn (int $age) => $age >= 23)->count())->toBeGreaterThanOrEqual(3);
});
