<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('mibeko:restaurer-pdf-journaux
            {plan : Plan JSON des PDF de Journaux officiels à restaurer}
            {--connection=pgsql : Connexion utilisée pour vérifier les fiches de JO}
            {--storage=s3 : Disque objet à contrôler ou à compléter}
            {--source-root= : Racine locale des sources immuables}
            {--limit= : Limite le nombre de PDF restaurés pendant cette exécution}
            {--execute : Téléverse les PDF ; sans cette option, dry-run}
            {--snapshot= : Snapshot JSON obligatoire avec --execute}
            {--rollback= : Snapshot d’une exécution précédente à annuler}')]
#[Description('Restaure de façon contrôlée les PDF absents des fiches de Journaux officiels')]
class RestaurerPdfJournauxCommand extends Command
{
    public function handle(): int
    {
        try {
            $planPath = $this->absolutePath((string) $this->argument('plan'));
            $plan = $this->readJson($planPath);
            $this->validatePlan($plan);

            $connectionName = (string) $this->option('connection');
            $storageName = (string) $this->option('storage');
            $execute = (bool) $this->option('execute');
            $limit = $this->validatedLimit($this->option('limit'));
            $rollbackPath = $this->option('rollback');

            $this->guardExecution($connectionName, $storageName, $execute);

            $sourceRoot = $this->sourceRoot();
            $sources = $this->verifySources($plan, $sourceRoot);
            $connection = DB::connection($connectionName);
            $this->verifyJournals($connection, $plan);

            $disk = Storage::disk($storageName);
            $state = $this->inspectObjects($disk, $plan);
            $planSha256 = hash_file('sha256', $planPath);

            if (is_string($rollbackPath) && $rollbackPath !== '') {
                return $this->rollback(
                    $disk,
                    $plan,
                    $planSha256,
                    $this->absolutePath($rollbackPath),
                    $execute,
                );
            }

            return $this->restore(
                $disk,
                $plan,
                $sources,
                $state,
                $planSha256,
                $execute,
                $limit,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $sources
     * @param  array{pending: list<string>, correct: list<string>}  $state
     */
    private function restore(
        FilesystemAdapter $disk,
        array $plan,
        array $sources,
        array $state,
        string $planSha256,
        bool $execute,
        ?int $limit,
    ): int {
        $pending = $state['pending'];
        $selected = $limit === null ? $pending : array_slice($pending, 0, $limit);

        $this->line(sprintf(
            'Plan validé : %d journal(aux), %d PDF à restaurer, %d déjà présent(s).',
            $plan['expected']['journal_rows'],
            count($pending),
            count($state['correct']),
        ));

        if ($pending === []) {
            $this->info('Correction déjà appliquée intégralement.');

            return self::SUCCESS;
        }

        if (! $execute) {
            if ($limit !== null) {
                $this->line(sprintf('Lot sélectionné : %d PDF sur %d restant(s).', count($selected), count($pending)));
            }
            $this->info('DRY-RUN OK — aucune écriture effectuée.');

            return self::SUCCESS;
        }

        $snapshotPath = $this->snapshotPath();
        $snapshot = $this->loadOrCreateSnapshot($snapshotPath, $plan, $planSha256);
        $itemsByKey = collect($plan['journals'])->keyBy('object_key');
        $written = 0;

        foreach ($selected as $objectKey) {
            /** @var array<string, mixed> $item */
            $item = $itemsByKey->get($objectKey);

            if ($disk->exists($objectKey)) {
                $this->verifyRemoteObject($disk, $item);

                continue;
            }

            if (! in_array($objectKey, $snapshot['objects_absent_before'], true)) {
                $snapshot['objects_absent_before'][] = $objectKey;
                $this->writeJson($snapshotPath, $snapshot);
            }

            $stream = fopen($sources[$objectKey], 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException("Source locale illisible : {$sources[$objectKey]}.");
            }

            try {
                $stored = $disk->put($objectKey, $stream, ['ContentType' => 'application/pdf']);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new RuntimeException("Échec du téléversement : {$objectKey}.");
            }

            $this->verifyRemoteObject($disk, $item);
            if (! in_array($objectKey, $snapshot['created_objects'], true)) {
                $snapshot['created_objects'][] = $objectKey;
                $this->writeJson($snapshotPath, $snapshot);
            }
            $written++;
        }

        $after = $this->inspectObjects($disk, $plan);
        $expectedRemaining = count($pending) - count($selected);
        if (count($after['pending']) !== $expectedRemaining) {
            throw new RuntimeException('Écart après exécution : le nombre de PDF restant est inattendu.');
        }

        $this->info(sprintf(
            'Correction appliquée : %d PDF restauré(s), %d restant(s). Snapshot : %s',
            $written,
            count($after['pending']),
            $snapshotPath,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function rollback(
        FilesystemAdapter $disk,
        array $plan,
        string $planSha256,
        string $snapshotPath,
        bool $execute,
    ): int {
        $snapshot = $this->readJson($snapshotPath);
        $this->validateSnapshot($snapshot, $plan, $planSha256);

        $itemsByKey = collect($plan['journals'])->keyBy('object_key');
        $present = [];
        $alreadyAbsent = [];

        foreach ($snapshot['objects_absent_before'] as $objectKey) {
            /** @var array<string, mixed> $item */
            $item = $itemsByKey->get($objectKey);
            $alreadyRemoved = in_array($objectKey, $snapshot['removed_objects'], true);
            if (! $disk->exists($objectKey)) {
                $alreadyAbsent[] = $objectKey;

                continue;
            }

            if ($alreadyRemoved) {
                throw new RuntimeException("Objet réapparu après le retour arrière : {$objectKey}.");
            }

            $this->verifyRemoteObject($disk, $item);
            $present[] = $objectKey;
        }

        $this->line(sprintf(
            'Retour arrière validé : %d objet(s) à retirer, %d déjà absent(s).',
            count($present),
            count($alreadyAbsent),
        ));

        if (! $execute) {
            $this->info('ROLLBACK DRY-RUN OK — aucune écriture effectuée.');

            return self::SUCCESS;
        }

        foreach ($present as $objectKey) {
            if (! $disk->delete($objectKey) || $disk->exists($objectKey)) {
                throw new RuntimeException("Retour arrière impossible pour {$objectKey}.");
            }

            if (! in_array($objectKey, $snapshot['removed_objects'], true)) {
                $snapshot['removed_objects'][] = $objectKey;
                $snapshot['rollback_dump_reference'] = $this->dumpReferenceIfConfigured();
                $this->writeJson($snapshotPath, $snapshot);
            }
        }

        $this->info('Retour arrière appliqué : les objets créés par ce snapshot sont absents.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, string>
     */
    private function verifySources(array $plan, string $sourceRoot): array
    {
        $sources = [];

        foreach ($plan['journals'] as $item) {
            $candidate = $sourceRoot.DIRECTORY_SEPARATOR.$item['source_path'];
            $path = realpath($candidate);
            if ($path === false || ! is_file($path)) {
                throw new RuntimeException("Source locale absente : {$candidate}.");
            }
            if (! str_starts_with($path, $sourceRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Source locale hors de la racine autorisée : {$candidate}.");
            }
            if (filesize($path) !== $item['file_size']) {
                throw new RuntimeException("Taille locale inattendue : {$path}.");
            }

            $actualSha256 = hash_file('sha256', $path);
            if (! hash_equals(strtolower($item['checksum_sha256']), $actualSha256)) {
                throw new RuntimeException("SHA-256 local inattendu : {$path}.");
            }

            $stream = fopen($path, 'rb');
            $magic = is_resource($stream) ? fread($stream, 5) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($magic !== '%PDF-') {
                throw new RuntimeException("La source n’est pas un PDF reconnu : {$path}.");
            }

            $sources[$item['object_key']] = $path;
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function verifyJournals(Connection $connection, array $plan): void
    {
        $published = 0;
        $livingDocuments = 0;

        foreach ($plan['journals'] as $item) {
            $journal = $connection->table('official_journals')
                ->where('id', $item['journal_id'])
                ->whereNull('deleted_at')
                ->first(['id', 'title', 'number', 'publication_date', 'file_path', 'is_published']);

            if ($journal === null) {
                throw new RuntimeException("Journal introuvable : {$item['journal_id']}.");
            }

            $expectedPath = "s3://{$plan['bucket']}/{$item['object_key']}";
            if ($journal->title !== $item['title']
                || (string) $journal->number !== $item['number']
                || (string) $journal->publication_date !== $item['publication_date']
                || $journal->file_path !== $expectedPath
                || (bool) $journal->is_published !== $item['is_published']) {
                throw new RuntimeException("Métadonnées inattendues pour le journal {$item['journal_id']}.");
            }

            $currentLivingDocuments = $connection->table('legal_documents')
                ->where('official_journal_id', $item['journal_id'])
                ->whereNull('deleted_at')
                ->count();
            if ($currentLivingDocuments !== $item['living_documents']) {
                throw new RuntimeException("Rattachements inattendus pour le journal {$item['journal_id']}.");
            }

            $published += (int) $journal->is_published;
            $livingDocuments += $currentLivingDocuments;
        }

        if (count($plan['journals']) !== $plan['expected']['journal_rows']
            || $published !== $plan['expected']['published_journals']
            || $livingDocuments !== $plan['expected']['living_documents']) {
            throw new RuntimeException('Les effectifs courants diffèrent du plan préparatoire.');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{pending: list<string>, correct: list<string>}
     */
    private function inspectObjects(FilesystemAdapter $disk, array $plan): array
    {
        $pending = [];
        $correct = [];

        foreach ($plan['journals'] as $item) {
            if (! $disk->exists($item['object_key'])) {
                $pending[] = $item['object_key'];

                continue;
            }

            $this->verifyRemoteObject($disk, $item);
            $correct[] = $item['object_key'];
        }

        return ['pending' => $pending, 'correct' => $correct];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function verifyRemoteObject(FilesystemAdapter $disk, array $item): void
    {
        $objectKey = $item['object_key'];
        if ($disk->size($objectKey) !== $item['file_size']) {
            throw new RuntimeException("Objet existant incompatible (taille) : {$objectKey}.");
        }

        $stream = $disk->readStream($objectKey);
        if (! is_resource($stream)) {
            throw new RuntimeException("Objet existant illisible : {$objectKey}.");
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualSha256 = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals(strtolower($item['checksum_sha256']), $actualSha256)) {
            throw new RuntimeException("Objet existant incompatible (SHA-256) : {$objectKey}.");
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function validatePlan(array $plan): void
    {
        if (($plan['version'] ?? null) !== 1
            || ! is_string($plan['bucket'] ?? null)
            || ! is_array($plan['expected'] ?? null)
            || ! is_array($plan['journals'] ?? null)) {
            throw new RuntimeException('Format de plan invalide.');
        }

        $requiredExpected = ['journal_rows', 'published_journals', 'living_documents', 'objects_to_restore', 'total_bytes'];
        foreach ($requiredExpected as $field) {
            if (! is_int($plan['expected'][$field] ?? null) || $plan['expected'][$field] < 0) {
                throw new RuntimeException("Effectif attendu invalide : {$field}.");
            }
        }

        if ($plan['expected']['journal_rows'] !== count($plan['journals'])
            || $plan['expected']['objects_to_restore'] !== count($plan['journals'])) {
            throw new RuntimeException('Le nombre d’entrées du plan est incohérent.');
        }

        $journalIds = [];
        $objectKeys = [];
        $sourcePaths = [];
        $totalBytes = 0;

        foreach ($plan['journals'] as $item) {
            foreach (['journal_id', 'title', 'number', 'publication_date', 'object_key', 'source_path', 'source_url', 'checksum_sha256'] as $field) {
                if (! is_string($item[$field] ?? null) || $item[$field] === '') {
                    throw new RuntimeException("Champ de journal invalide : {$field}.");
                }
            }
            if (! is_bool($item['is_published'] ?? null)
                || ! is_int($item['living_documents'] ?? null)
                || ! is_int($item['file_size'] ?? null)
                || $item['file_size'] <= 0
                || ! preg_match('/^[a-f0-9]{64}$/i', $item['checksum_sha256'])
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['publication_date'])) {
                throw new RuntimeException("Valeurs invalides pour le journal {$item['journal_id']}.");
            }
            if (str_starts_with($item['source_path'], '/')
                || str_contains($item['source_path'], '..')
                || str_starts_with($item['object_key'], '/')
                || ! str_ends_with(strtolower($item['object_key']), '.pdf')
                || basename($item['source_path']) !== basename($item['object_key'])) {
                throw new RuntimeException("Chemins invalides pour le journal {$item['journal_id']}.");
            }

            $sourceHost = strtolower((string) parse_url($item['source_url'], PHP_URL_HOST));
            if (parse_url($item['source_url'], PHP_URL_SCHEME) !== 'https'
                || ! in_array($sourceHost, ['sgg.cg', 'www.sgg.cg'], true)) {
                throw new RuntimeException("Source non officielle pour le journal {$item['journal_id']}.");
            }

            $journalIds[] = $item['journal_id'];
            $objectKeys[] = $item['object_key'];
            $sourcePaths[] = $item['source_path'];
            $totalBytes += $item['file_size'];
        }

        if (count(array_unique($journalIds)) !== count($journalIds)
            || count(array_unique($objectKeys)) !== count($objectKeys)
            || count(array_unique($sourcePaths)) !== count($sourcePaths)
            || $totalBytes !== $plan['expected']['total_bytes']) {
            throw new RuntimeException('Doublon ou total d’octets incohérent dans le plan.');
        }
    }

    private function guardExecution(string $connectionName, string $storageName, bool $execute): void
    {
        if (! $execute) {
            return;
        }

        if (Str::endsWith($storageName, '_ro')) {
            throw new RuntimeException('Exécution refusée sur un stockage en lecture seule.');
        }

        if ($storageName !== 's3_prod_rw') {
            return;
        }

        if ($connectionName !== 'pgsql_prod_ro') {
            throw new RuntimeException('La vérification DB de production doit rester sur pgsql_prod_ro.');
        }

        $config = config('filesystems.disks.s3_prod_rw');
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $field) {
            if (blank($config[$field] ?? null)) {
                throw new RuntimeException("Configuration MinIO d’écriture absente : {$field}.");
            }
        }

        $rwEndpoint = rtrim((string) $config['endpoint'], '/');
        $roEndpoint = rtrim((string) config('filesystems.disks.s3_prod_ro.endpoint'), '/');
        if ($rwEndpoint !== $roEndpoint
            || $config['bucket'] !== config('filesystems.disks.s3_prod_ro.bucket')
            || parse_url($rwEndpoint, PHP_URL_PORT) === 9000) {
            throw new RuntimeException('La cible MinIO RW ne correspond pas au stockage de production vérifié en lecture seule.');
        }

        $this->freshDumpReference();
    }

    private function freshDumpReference(): string
    {
        $dumpReference = getenv('MIBEKO_DUMP_REFERENCE') ?: '';
        if (! is_file($dumpReference) || filesize($dumpReference) === 0) {
            throw new RuntimeException('MIBEKO_DUMP_REFERENCE doit désigner un dump frais et non vide.');
        }

        $age = time() - filemtime($dumpReference);
        if ($age < 0 || $age > 7200) {
            throw new RuntimeException('MIBEKO_DUMP_REFERENCE est trop ancien (maximum : 2 heures).');
        }

        return realpath($dumpReference) ?: $dumpReference;
    }

    private function dumpReferenceIfConfigured(): ?string
    {
        return (getenv('MIBEKO_DUMP_REFERENCE') ?: '') !== ''
            ? $this->freshDumpReference()
            : null;
    }

    private function sourceRoot(): string
    {
        $option = $this->option('source-root');
        $candidate = is_string($option) && $option !== ''
            ? $this->absolutePath($option)
            : base_path('../mibeko-python/data');
        $root = realpath($candidate);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException("Racine des sources introuvable : {$candidate}.");
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function validatedLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! ctype_digit((string) $value) || (int) $value <= 0) {
            throw new RuntimeException('--limit doit être un entier strictement positif.');
        }

        return (int) $value;
    }

    private function snapshotPath(): string
    {
        $snapshot = $this->option('snapshot');
        if (! is_string($snapshot) || $snapshot === '') {
            throw new RuntimeException('--snapshot est obligatoire avec --execute.');
        }

        return $this->absolutePath($snapshot);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function loadOrCreateSnapshot(string $path, array $plan, string $planSha256): array
    {
        if (is_file($path)) {
            $snapshot = $this->readJson($path);
            $this->validateSnapshot($snapshot, $plan, $planSha256);
            if (($snapshot['removed_objects'] ?? []) !== []) {
                throw new RuntimeException('Ce snapshot a déjà servi à un retour arrière.');
            }

            return $snapshot;
        }

        $snapshot = [
            'version' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'plan_sha256' => $planSha256,
            'bucket' => $plan['bucket'],
            'dump_reference' => $this->dumpReferenceIfConfigured(),
            'objects_absent_before' => [],
            'created_objects' => [],
            'removed_objects' => [],
        ];
        $this->writeJson($path, $snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $plan
     */
    private function validateSnapshot(array $snapshot, array $plan, string $planSha256): void
    {
        if (($snapshot['version'] ?? null) !== 1
            || ! hash_equals((string) ($snapshot['plan_sha256'] ?? ''), $planSha256)
            || ($snapshot['bucket'] ?? null) !== $plan['bucket']
            || ! is_array($snapshot['objects_absent_before'] ?? null)
            || ! is_array($snapshot['created_objects'] ?? null)
            || ! is_array($snapshot['removed_objects'] ?? null)) {
            throw new RuntimeException('Snapshot incompatible avec ce plan.');
        }

        $allowed = collect($plan['journals'])->pluck('object_key')->all();
        foreach (['objects_absent_before', 'created_objects', 'removed_objects'] as $field) {
            if (array_diff($snapshot[$field], $allowed) !== []
                || count(array_unique($snapshot[$field])) !== count($snapshot[$field])) {
                throw new RuntimeException("Snapshot invalide : {$field} contient une clé étrangère au plan.");
            }
        }
        if (array_diff($snapshot['created_objects'], $snapshot['objects_absent_before']) !== []
            || array_diff($snapshot['removed_objects'], $snapshot['objects_absent_before']) !== []) {
            throw new RuntimeException('Snapshot invalide : historique d’objets incohérent.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fichier JSON introuvable : {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Contenu JSON invalide : {$path}.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier du snapshot : {$directory}.");
        }

        $temporary = $path.'.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new RuntimeException("Impossible d’écrire le snapshot : {$path}.");
        }
    }

    private function absolutePath(string $path): string
    {
        if (Str::startsWith($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
