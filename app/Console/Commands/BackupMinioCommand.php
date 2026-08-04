<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Sauvegarde le bucket MinIO (S3) vers une destination hors-site.
 *
 * Le corpus (PDF sources, immuables) vit sur MinIO — disk `s3`, bucket
 * `mibeko-documents`. La sauvegarde quotidienne existante (`mibeko:backup`)
 * ne couvre QUE la base de données (`--only-db`) : son option `--only-files`
 * sauvegarde le CODE de l'application (`config/backup.php` > source > files >
 * include = `base_path()`), pas le contenu d'un disque S3 distant — Spatie
 * Backup ne sait pas parcourir un bucket.
 *
 * La destination par défaut est `gdrive`, déjà câblée pour la sauvegarde de
 * la base (docs/infra/production.md § 2.4) — donc distincte du bucket
 * lui-même, comme l'exige la documentation : un incident qui abîme
 * `mibeko-documents` ne doit pas emporter avec lui le moyen de le restaurer.
 *
 * Une seule archive zip par exécution (comme le dump quotidien de la base) :
 * plus simple à monitorer et à restaurer qu'un miroir objet par objet, et
 * évite le rate-limit de l'API Google Drive sur plusieurs centaines de
 * petits fichiers.
 */
class BackupMinioCommand extends Command
{
    protected $signature = 'mibeko:backup-minio
        {--source=s3 : Disk source (le bucket MinIO du corpus)}
        {--destination=gdrive : Disk destination de la sauvegarde, distinct du bucket source}
        {--prefix= : Sous-dossier du disk source à sauvegarder (défaut : tout le bucket)}';

    protected $description = 'Sauvegarde le contenu du bucket MinIO (PDF sources) vers une destination hors-site.';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $destination = (string) $this->option('destination');
        $prefix = (string) ($this->option('prefix') ?: '');

        if ($source === $destination) {
            $this->error('Source et destination identiques : la sauvegarde doit être hors-site du bucket source.');

            return self::FAILURE;
        }

        if (! is_array(config("filesystems.disks.{$source}"))) {
            $this->error("Le disk source '{$source}' n'existe pas dans config/filesystems.php.");

            return self::FAILURE;
        }

        if (! is_array(config("filesystems.disks.{$destination}"))) {
            $this->error("Le disk destination '{$destination}' n'existe pas dans config/filesystems.php.");

            return self::FAILURE;
        }

        $sourceDisk = Storage::disk($source);
        $fichiers = $sourceDisk->allFiles($prefix);

        if ($fichiers === []) {
            $this->warn("Aucun fichier trouvé sur '{$source}'".($prefix !== '' ? " (préfixe {$prefix})" : '').'.');

            return self::SUCCESS;
        }

        $nomApp = (string) config('backup.backup.name', 'mibeko');
        $horodatage = now()->format('Y-m-d-His');
        $nomArchive = "{$nomApp}-minio-{$horodatage}.zip";
        $dossierTmp = storage_path('app/backup-minio-tmp');
        $cheminLocal = "{$dossierTmp}/{$nomArchive}";

        if (! is_dir($dossierTmp) && ! mkdir($dossierTmp, 0755, true) && ! is_dir($dossierTmp)) {
            $this->error("Impossible de créer le dossier temporaire : {$dossierTmp}");

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($cheminLocal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Impossible de créer l'archive locale : {$cheminLocal}");

            return self::FAILURE;
        }

        $tailleContenu = 0;
        $barre = $this->output->createProgressBar(count($fichiers));
        $barre->start();

        foreach ($fichiers as $fichier) {
            $contenu = $sourceDisk->get($fichier);
            $zip->addFromString($fichier, (string) $contenu);
            $tailleContenu += strlen((string) $contenu);
            $barre->advance();
        }

        $barre->finish();
        $this->newLine();
        $zip->close();

        $tailleArchive = filesize($cheminLocal) ?: 0;
        $this->info(sprintf(
            '%d fichier(s), %s de contenu source → archive de %s.',
            count($fichiers),
            $this->formatOctets($tailleContenu),
            $this->formatOctets($tailleArchive),
        ));

        $cheminDistant = "{$nomApp}/minio/{$nomArchive}";
        $flux = fopen($cheminLocal, 'r');
        $envoye = $flux !== false && Storage::disk($destination)->put($cheminDistant, $flux);

        if (is_resource($flux)) {
            fclose($flux);
        }

        @unlink($cheminLocal);

        if (! $envoye) {
            $this->error("Échec de l'envoi vers '{$destination}:{$cheminDistant}'.");

            return self::FAILURE;
        }

        $this->info("Sauvegarde envoyée : {$destination}:{$cheminDistant}");

        return self::SUCCESS;
    }

    private function formatOctets(int $octets): string
    {
        $unites = ['o', 'Ko', 'Mo', 'Go'];
        $valeur = $octets;
        $indice = 0;

        while ($valeur >= 1024 && $indice < count($unites) - 1) {
            $valeur /= 1024;
            $indice++;
        }

        return sprintf('%.1f %s', $valeur, $unites[$indice]);
    }
}
