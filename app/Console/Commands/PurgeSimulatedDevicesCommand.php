<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Désactive les appareils porteurs d'un jeton FCM simulé.
 *
 * Le simulateur iOS a longtemps enregistré des jetons factices
 * (`fcm_token_simulated_ios_…`) : ces lignes polluent la table `devices` en
 * production et font échouer un envoi FCM v1 sur chacune d'elles.
 *
 * La commande est en SIMULATION par défaut ; il faut `--force` pour écrire.
 * Elle DÉSACTIVE (status = inactive) au lieu de supprimer : la suppression
 * physique en production est un interdit absolu du dépôt, et le statut suffit —
 * `Device::pushable()` écarte déjà ces lignes de tout envoi.
 */
class PurgeSimulatedDevicesCommand extends Command
{
    protected $signature = 'mibeko:purge-simulated-devices
                            {--force : Applique réellement la désactivation (sans ce drapeau, simulation seule)}
                            {--dry-run : Simulation explicite — comportement par défaut, ignoré si --force}';

    protected $description = 'Désactive les appareils dont le jeton push est un jeton simulé du simulateur iOS.';

    public function handle(): int
    {
        $apply = $this->option('force') && ! $this->option('dry-run');

        $query = Device::query()
            ->where('push_token', 'like', Device::simulatedTokenPattern())
            ->where('status', '!=', 'inactive');

        $total = (int) $query->clone()->count();

        if ($total === 0) {
            $this->info('Aucun appareil simulé actif à désactiver.');

            return self::SUCCESS;
        }

        $this->table(
            ['device_id', 'plateforme', 'jeton', 'statut'],
            $query->clone()
                ->orderBy('created_at')
                ->limit(20)
                ->get(['device_id', 'platform', 'push_token', 'status'])
                ->map(fn (Device $device) => [
                    $device->device_id,
                    $device->platform,
                    Str::limit((string) $device->push_token, 40),
                    $device->status,
                ])
                ->all()
        );

        if ($total > 20) {
            $this->line('… et '.($total - 20).' autre(s).');
        }

        if (! $apply) {
            $this->warn("SIMULATION — {$total} appareil(s) simulé(s) seraient désactivé(s). Relancez avec --force pour appliquer.");

            return self::SUCCESS;
        }

        $updated = $query->update(['status' => 'inactive']);

        $this->info("Appareils simulés désactivés : {$updated}");

        return self::SUCCESS;
    }
}
