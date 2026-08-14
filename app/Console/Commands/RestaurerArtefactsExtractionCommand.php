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

#[Signature('mibeko:restaurer-artefacts-extraction
            {plan : Plan JSON des artefacts Markdown et JSON à restaurer}
            {--connection=pgsql : Connexion utilisée pour vérifier les références média}
            {--storage=s3 : Disque objet à contrôler ou à compléter}
            {--source-root= : Racine locale des artefacts MinerU}
            {--limit= : Limite le nombre d’artefacts restaurés pendant cette exécution}
            {--execute : Téléverse les artefacts ; sans cette option, dry-run}
            {--snapshot= : Snapshot JSON obligatoire avec --execute}
            {--rollback= : Snapshot d’une exécution précédente à annuler}')]
#[Description('Restaure de façon contrôlée les artefacts Markdown et JSON absents de MinIO')]
class RestaurerArtefactsExtractionCommand extends Command
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

            $this->guardExecution($connectionName, $storageName, $plan['bucket'], $execute);

            $sources = $this->verifySources($plan, $this->sourceRoot());
            $this->verifyMediaReferences(DB::connection($connectionName), $plan);

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
            'Plan validé : %d artefact(s), %d à restaurer, %d déjà présent(s), %d référence(s) média.',
            $plan['expected']['object_rows'],
            count($pending),
            count($state['correct']),
            $plan['expected']['media_references'],
        ));

        if ($pending === []) {
            $this->info('Correction déjà appliquée intégralement.');

            return self::SUCCESS;
        }

        if (! $execute) {
            if ($limit !== null) {
                $this->line(sprintf('Lot sélectionné : %d artefact(s) sur %d restant(s).', count($selected), count($pending)));
            }
            $this->info('DRY-RUN OK — aucune écriture effectuée.');

            return self::SUCCESS;
        }

        $snapshotPath = $this->snapshotPath();
        $snapshot = $this->loadOrCreateSnapshot($snapshotPath, $plan, $planSha256);
        $itemsByKey = collect($plan['objects'])->keyBy('object_key');
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
                $stored = $disk->put($objectKey, $stream, [
                    'ContentType' => $item['mime_type'],
                ]);
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
            throw new RuntimeException('Écart après exécution : le nombre d’artefacts restant est inattendu.');
        }

        $this->info(sprintf(
            'Correction appliquée : %d artefact(s) restauré(s), %d restant(s). Snapshot : %s',
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

        $itemsByKey = collect($plan['objects'])->keyBy('object_key');
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

        foreach ($plan['objects'] as $item) {
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

            if ($item['file_category'] === 'EXTRACTION_JSON') {
                try {
                    json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new RuntimeException("La source JSON est invalide : {$path}.");
                }
            } elseif (trim((string) file_get_contents($path)) === '') {
                throw new RuntimeException("La source Markdown est vide : {$path}.");
            }

            $sources[$item['object_key']] = $path;
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function verifyMediaReferences(Connection $connection, array $plan): void
    {
        $documentIds = [];
        $publishedDocumentIds = [];
        $draftDocumentIds = [];
        $referenceCount = 0;

        foreach ($plan['objects'] as $item) {
            $rows = $connection->table('media_files as media')
                ->join('legal_documents as documents', 'documents.id', '=', 'media.document_id')
                ->whereNull('documents.deleted_at')
                ->where('media.object_key', $item['object_key'])
                ->get([
                    'media.document_id',
                    'media.file_path',
                    'media.storage_provider',
                    'media.bucket_name',
                    'media.original_filename',
                    'media.mime_type',
                    'media.file_category',
                    'media.file_size',
                    'media.checksum_sha256',
                    'documents.curation_status',
                ]);

            $expectedPath = "s3://{$plan['bucket']}/{$item['object_key']}";
            foreach ($rows as $row) {
                if ($row->file_path !== $expectedPath
                    || $row->storage_provider !== 'MINIO'
                    || $row->bucket_name !== $plan['bucket']
                    || $row->original_filename !== basename($item['object_key'])
                    || $row->mime_type !== $item['mime_type']
                    || $row->file_category !== $item['file_category']
                    || (int) $row->file_size !== $item['file_size']
                    || ! hash_equals(strtolower($item['checksum_sha256']), strtolower((string) $row->checksum_sha256))) {
                    throw new RuntimeException("Métadonnées média inattendues : {$item['object_key']}.");
                }

                $documentIds[$row->document_id] = true;
                if ($row->curation_status === 'published') {
                    $publishedDocumentIds[$row->document_id] = true;
                } elseif ($row->curation_status === 'draft') {
                    $draftDocumentIds[$row->document_id] = true;
                } else {
                    throw new RuntimeException("Statut de curation inattendu : {$row->document_id}.");
                }
            }

            $currentDocuments = $rows->pluck('document_id')->unique()->count();
            $currentPublished = $rows->where('curation_status', 'published')->pluck('document_id')->unique()->count();
            $currentDraft = $rows->where('curation_status', 'draft')->pluck('document_id')->unique()->count();
            if ($rows->count() !== $item['references']
                || $currentDocuments !== $item['documents']
                || $currentPublished !== $item['published_documents']
                || $currentDraft !== $item['draft_documents']) {
                throw new RuntimeException("Effectifs média inattendus : {$item['object_key']}.");
            }

            $referenceCount += $rows->count();
        }

        if ($referenceCount !== $plan['expected']['media_references']
            || count($documentIds) !== $plan['expected']['documents']
            || count($publishedDocumentIds) !== $plan['expected']['published_documents']
            || count($draftDocumentIds) !== $plan['expected']['draft_documents']) {
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

        foreach ($plan['objects'] as $item) {
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
            || ! is_array($plan['objects'] ?? null)) {
            throw new RuntimeException('Format de plan invalide.');
        }

        $requiredExpected = [
            'object_rows',
            'media_references',
            'documents',
            'published_documents',
            'draft_documents',
            'markdown_objects',
            'json_objects',
            'total_bytes',
        ];
        foreach ($requiredExpected as $field) {
            if (! is_int($plan['expected'][$field] ?? null) || $plan['expected'][$field] < 0) {
                throw new RuntimeException("Effectif attendu invalide : {$field}.");
            }
        }

        if ($plan['expected']['object_rows'] !== count($plan['objects'])
            || $plan['expected']['documents'] !== $plan['expected']['published_documents'] + $plan['expected']['draft_documents']) {
            throw new RuntimeException('Les effectifs globaux du plan sont incohérents.');
        }

        $objectKeys = [];
        $sourcePaths = [];
        $totalBytes = 0;
        $totalReferences = 0;
        $categoryCounts = [
            'EXTRACTION_MARKDOWN' => 0,
            'EXTRACTION_JSON' => 0,
        ];

        foreach ($plan['objects'] as $item) {
            foreach (['object_key', 'source_path', 'mime_type', 'file_category', 'checksum_sha256'] as $field) {
                if (! is_string($item[$field] ?? null) || $item[$field] === '') {
                    throw new RuntimeException("Champ d’artefact invalide : {$field}.");
                }
            }
            foreach (['file_size', 'references', 'documents', 'published_documents', 'draft_documents'] as $field) {
                if (! is_int($item[$field] ?? null) || $item[$field] < 0) {
                    throw new RuntimeException("Effectif d’artefact invalide : {$field}.");
                }
            }
            if ($item['file_size'] <= 0
                || $item['references'] <= 0
                || $item['references'] < $item['documents']
                || $item['documents'] !== $item['published_documents'] + $item['draft_documents']
                || ! preg_match('/^[a-f0-9]{64}$/i', $item['checksum_sha256'])
                || ! array_key_exists($item['file_category'], $categoryCounts)) {
                throw new RuntimeException("Valeurs invalides pour l’artefact {$item['object_key']}.");
            }

            $extension = $item['file_category'] === 'EXTRACTION_JSON' ? '.json' : '.md';
            $mimeType = $item['file_category'] === 'EXTRACTION_JSON' ? 'application/json' : 'text/markdown';
            $categoryPath = $item['file_category'] === 'EXTRACTION_JSON' ? '/extractions/json/' : '/extractions/markdown/';
            if (str_starts_with($item['source_path'], '/')
                || str_contains($item['source_path'], '..')
                || str_starts_with($item['object_key'], '/')
                || ! str_starts_with($item['object_key'], 'domino/legal-documents/flux/')
                || ! str_contains($item['object_key'], $categoryPath)
                || ! str_ends_with(strtolower($item['object_key']), $extension)
                || basename($item['source_path']) !== basename($item['object_key'])
                || $item['mime_type'] !== $mimeType) {
                throw new RuntimeException("Chemins ou type invalides pour l’artefact {$item['object_key']}.");
            }

            $objectKeys[] = $item['object_key'];
            $sourcePaths[] = $item['source_path'];
            $totalBytes += $item['file_size'];
            $totalReferences += $item['references'];
            $categoryCounts[$item['file_category']]++;
        }

        if (count(array_unique($objectKeys)) !== count($objectKeys)
            || count(array_unique($sourcePaths)) !== count($sourcePaths)
            || $totalBytes !== $plan['expected']['total_bytes']
            || $totalReferences !== $plan['expected']['media_references']
            || $categoryCounts['EXTRACTION_MARKDOWN'] !== $plan['expected']['markdown_objects']
            || $categoryCounts['EXTRACTION_JSON'] !== $plan['expected']['json_objects']) {
            throw new RuntimeException('Doublon ou total incohérent dans le plan.');
        }
    }

    private function guardExecution(string $connectionName, string $storageName, string $bucket, bool $execute): void
    {
        $configuredBucket = config("filesystems.disks.{$storageName}.bucket");
        if (is_string($configuredBucket) && $configuredBucket !== '' && $configuredBucket !== $bucket) {
            throw new RuntimeException('Le bucket du plan ne correspond pas au stockage sélectionné.');
        }

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

        $allowed = collect($plan['objects'])->pluck('object_key')->all();
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
