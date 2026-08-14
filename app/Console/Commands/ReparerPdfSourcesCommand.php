<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\OfficialJournal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('mibeko:reparer-pdf-sources
            {plan : Plan JSON des références à corriger}
            {--connection=pgsql : Connexion de base de données}
            {--storage=s3 : Disque utilisé pour vérifier les PDF cibles}
            {--execute : Applique la correction ; sans cette option, dry-run}
            {--snapshot= : Fichier JSON de retour arrière, obligatoire avec --execute}
            {--rollback= : Snapshot produit par une exécution précédente}')]
#[Description('Repointage contrôlé et réversible des PDF sources vers des objets MinIO identiques')]
class ReparerPdfSourcesCommand extends Command
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
            $rollbackPath = $this->option('rollback');

            $this->guardExecution($connectionName, $execute, $rollbackPath);
            $this->verifyTargets($plan, $storageName);

            $connection = DB::connection($connectionName);

            if (is_string($rollbackPath) && $rollbackPath !== '') {
                return $this->rollback(
                    $connection,
                    $plan,
                    hash_file('sha256', $planPath),
                    $this->absolutePath($rollbackPath),
                    $execute,
                );
            }

            return $this->repair(
                $connection,
                $plan,
                hash_file('sha256', $planPath),
                $execute,
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function repair(Connection $connection, array $plan, string $planSha256, bool $execute): int
    {
        $state = $this->currentState($connection, $plan);
        $expected = $plan['expected'];

        $this->assertExpectedCounts($state, $expected);

        if ($state['target_media_rows'] === $expected['media_rows']
            && $state['target_journal_rows'] === $expected['journal_rows']) {
            $this->info('Correction déjà appliquée intégralement.');

            return Command::SUCCESS;
        }

        if ($state['source_media_rows'] !== $expected['media_rows']
            || $state['source_journal_rows'] !== $expected['journal_rows']) {
            throw new RuntimeException('État partiel ou inattendu : aucune écriture ne sera effectuée.');
        }

        $this->displayPlan($state, $expected);

        if (! $execute) {
            $this->info('DRY-RUN OK — aucune écriture effectuée.');

            return Command::SUCCESS;
        }

        $snapshotPath = $this->snapshotPath();
        $snapshot = [
            'version' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'plan_sha256' => $planSha256,
            'connection' => $connection->getName(),
            'dump_reference' => getenv('MIBEKO_DUMP_REFERENCE') ?: null,
            'before' => [
                'media' => $state['media'],
                'journals' => $state['journals'],
            ],
        ];
        $this->writeSnapshot($snapshotPath, $snapshot);

        $connection->transaction(function () use ($connection, $plan): void {
            $this->applyMediaRepoints($connection, $plan);
            $this->applyJournalRepoints($connection, $plan);

            $after = $this->currentState($connection, $plan);
            if ($after['target_media_rows'] !== $plan['expected']['media_rows']
                || $after['target_journal_rows'] !== $plan['expected']['journal_rows']) {
                throw new RuntimeException('Écart après exécution : transaction annulée.');
            }
        });

        $this->info("Correction appliquée. Snapshot de retour arrière : {$snapshotPath}");

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function rollback(
        Connection $connection,
        array $plan,
        string $planSha256,
        string $snapshotPath,
        bool $execute,
    ): int {
        $snapshot = $this->readJson($snapshotPath);

        if (($snapshot['version'] ?? null) !== 1
            || ! hash_equals((string) ($snapshot['plan_sha256'] ?? ''), $planSha256)
            || ($snapshot['connection'] ?? null) !== $connection->getName()) {
            throw new RuntimeException('Snapshot incompatible avec ce plan de correction.');
        }

        $beforeMedia = collect(Arr::get($snapshot, 'before.media'));
        $beforeJournals = collect(Arr::get($snapshot, 'before.journals'));
        if ($beforeMedia->count() !== $plan['expected']['media_rows']
            || $beforeJournals->count() !== $plan['expected']['journal_rows']) {
            throw new RuntimeException('Snapshot incomplet : retour arrière refusé.');
        }

        $state = $this->currentState($connection, $plan);
        if ($state['target_media_rows'] !== $plan['expected']['media_rows']
            || $state['target_journal_rows'] !== $plan['expected']['journal_rows']) {
            throw new RuntimeException('La base n’est pas dans l’état cible intégral : retour arrière refusé.');
        }

        $this->line(sprintf(
            'Retour arrière : %d média(s) et %d journal(aux).',
            $beforeMedia->count(),
            $beforeJournals->count(),
        ));

        if (! $execute) {
            $this->info('ROLLBACK DRY-RUN OK — aucune écriture effectuée.');

            return Command::SUCCESS;
        }

        $connection->transaction(function () use ($beforeJournals, $beforeMedia, $connection, $plan): void {
            foreach ($beforeMedia as $row) {
                $targetObjectKey = $this->targetObjectKeyForMedia($plan, $row['id']);
                $updated = $connection->table((new MediaFile)->getTable())
                    ->where('id', $row['id'])
                    ->where('file_path', $this->s3Path($targetObjectKey))
                    ->where('object_key', $targetObjectKey)
                    ->update([
                        'file_path' => $row['file_path'],
                        'object_key' => $row['object_key'],
                        'updated_at' => $row['updated_at'],
                    ]);

                if ($updated !== 1) {
                    throw new RuntimeException("Retour arrière média impossible pour {$row['id']}.");
                }
            }

            foreach ($beforeJournals as $row) {
                $targetObjectKey = $this->targetObjectKeyForJournal($plan, $row['id']);
                $updated = $connection->table((new OfficialJournal)->getTable())
                    ->where('id', $row['id'])
                    ->where('file_path', $this->s3Path($targetObjectKey))
                    ->update([
                        'file_path' => $row['file_path'],
                        'updated_at' => $row['updated_at'],
                    ]);

                if ($updated !== 1) {
                    throw new RuntimeException("Retour arrière journal impossible pour {$row['id']}.");
                }
            }

            $after = $this->currentState($connection, $plan);
            if ($after['source_media_rows'] !== $plan['expected']['media_rows']
                || $after['source_journal_rows'] !== $plan['expected']['journal_rows']) {
                throw new RuntimeException('Écart après retour arrière : transaction annulée.');
            }
        });

        $this->info('Retour arrière appliqué.');

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function currentState(Connection $connection, array $plan): array
    {
        $mediaRows = collect();
        $sourceMediaRows = 0;
        $targetMediaRows = 0;

        foreach ($plan['media_repoints'] as $mapping) {
            $rows = $connection->table((new MediaFile)->getTable().' as media')
                ->join((new LegalDocument)->getTable().' as documents', 'documents.id', '=', 'media.document_id')
                ->whereNull('documents.deleted_at')
                ->whereIn('media.id', $mapping['media_ids'])
                ->get([
                    'media.id',
                    'media.document_id',
                    'media.file_path',
                    'media.object_key',
                    'media.file_category',
                    'media.file_size',
                    'media.checksum_sha256',
                    'media.updated_at',
                    'documents.curation_status',
                ]);

            if ($rows->count() !== count($mapping['media_ids'])) {
                throw new RuntimeException("Effectif média inattendu pour {$mapping['old_object_key']}.");
            }

            foreach ($rows as $row) {
                if ($row->file_category !== 'SOURCE_PDF'
                    || ! hash_equals(strtolower($mapping['checksum_sha256']), strtolower((string) $row->checksum_sha256))
                    || (int) $row->file_size !== $mapping['file_size']) {
                    throw new RuntimeException("Métadonnées média inattendues pour {$row->id}.");
                }

                if ($row->object_key === $mapping['old_object_key']
                    && $row->file_path === $this->s3Path($mapping['old_object_key'])) {
                    $sourceMediaRows++;
                } elseif ($row->object_key === $mapping['target_object_key']
                    && $row->file_path === $this->s3Path($mapping['target_object_key'])) {
                    $targetMediaRows++;
                } else {
                    throw new RuntimeException("Chemin ou clé média inattendu pour {$row->id}.");
                }
            }

            $mediaRows = $mediaRows->concat($rows);
        }

        $journalRows = collect();
        $sourceJournalRows = 0;
        $targetJournalRows = 0;

        foreach ($plan['journal_repoints'] as $mapping) {
            $row = $connection->table((new OfficialJournal)->getTable())
                ->where('id', $mapping['journal_id'])
                ->whereNull('deleted_at')
                ->first(['id', 'file_path', 'updated_at']);

            if ($row === null) {
                throw new RuntimeException("Journal introuvable : {$mapping['journal_id']}.");
            }

            if ($row->file_path === $mapping['old_file_path']) {
                $sourceJournalRows++;
            } elseif ($row->file_path === $this->s3Path($mapping['target_object_key'])) {
                $targetJournalRows++;
            } else {
                throw new RuntimeException("Chemin inattendu pour le journal {$mapping['journal_id']}.");
            }

            $journalRows->push($row);
        }

        return [
            'media' => $mediaRows->map(fn (object $row): array => (array) $row)->values()->all(),
            'journals' => $journalRows->map(fn (object $row): array => (array) $row)->values()->all(),
            'source_media_rows' => $sourceMediaRows,
            'target_media_rows' => $targetMediaRows,
            'source_journal_rows' => $sourceJournalRows,
            'target_journal_rows' => $targetJournalRows,
            'published_documents' => $mediaRows->where('curation_status', LegalDocument::STATUS_PUBLISHED)
                ->pluck('document_id')->unique()->count(),
            'draft_documents' => $mediaRows->where('curation_status', LegalDocument::STATUS_DRAFT)
                ->pluck('document_id')->unique()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyMediaRepoints(Connection $connection, array $plan): void
    {
        foreach ($plan['media_repoints'] as $mapping) {
            $updated = $connection->table((new MediaFile)->getTable())
                ->whereIn('id', $mapping['media_ids'])
                ->where('object_key', $mapping['old_object_key'])
                ->where('file_path', $this->s3Path($mapping['old_object_key']))
                ->update([
                    'file_path' => $this->s3Path($mapping['target_object_key']),
                    'object_key' => $mapping['target_object_key'],
                    'updated_at' => now(),
                ]);

            if ($updated !== count($mapping['media_ids'])) {
                throw new RuntimeException("Écart d’écriture média pour {$mapping['old_object_key']}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyJournalRepoints(Connection $connection, array $plan): void
    {
        foreach ($plan['journal_repoints'] as $mapping) {
            $updated = $connection->table((new OfficialJournal)->getTable())
                ->where('id', $mapping['journal_id'])
                ->where('file_path', $mapping['old_file_path'])
                ->update([
                    'file_path' => $this->s3Path($mapping['target_object_key']),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException("Écart d’écriture pour le journal {$mapping['journal_id']}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function verifyTargets(array $plan, string $storageName): void
    {
        $targets = collect($plan['media_repoints'])
            ->concat($plan['journal_repoints'])
            ->keyBy('target_object_key');
        $disk = Storage::disk($storageName);

        foreach ($targets as $target) {
            $path = $target['target_object_key'];
            if (! $disk->exists($path)) {
                throw new RuntimeException("PDF cible absent du stockage : {$path}.");
            }

            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException("PDF cible illisible : {$path}.");
            }

            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            $actualSha256 = hash_final($hash);

            if (! hash_equals(strtolower($target['checksum_sha256']), $actualSha256)) {
                throw new RuntimeException("SHA-256 inattendu pour le PDF cible : {$path}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $expected
     */
    private function assertExpectedCounts(array $state, array $expected): void
    {
        if (count($state['media']) !== $expected['media_rows']
            || count($state['journals']) !== $expected['journal_rows']
            || $state['published_documents'] !== $expected['published_documents']
            || $state['draft_documents'] !== $expected['draft_documents']) {
            throw new RuntimeException('Les effectifs courants diffèrent du plan préparatoire.');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, int>  $expected
     */
    private function displayPlan(array $state, array $expected): void
    {
        $this->line(sprintf(
            'Plan validé : %d références média (%d publiées, %d brouillons) et %d journaux.',
            count($state['media']),
            $expected['published_documents'],
            $expected['draft_documents'],
            count($state['journals']),
        ));
    }

    private function guardExecution(string $connectionName, bool $execute, mixed $rollbackPath): void
    {
        if (! $execute) {
            return;
        }

        if (Str::endsWith($connectionName, '_ro')) {
            throw new RuntimeException('Exécution refusée sur une connexion en lecture seule.');
        }

        if (! is_string($rollbackPath) || $rollbackPath === '') {
            $this->snapshotPath();
        }

        if ($connectionName !== 'pgsql_prod_rw') {
            return;
        }

        $dumpPath = getenv('MIBEKO_DUMP_REFERENCE') ?: '';
        if (! is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new RuntimeException('MIBEKO_DUMP_REFERENCE doit désigner un dump frais et non vide.');
        }

        $age = time() - filemtime($dumpPath);
        if ($age < 0 || $age > 7200) {
            throw new RuntimeException('Le dump de référence a plus de deux heures.');
        }
    }

    private function snapshotPath(): string
    {
        $path = (string) $this->option('snapshot');
        if ($path === '') {
            throw new RuntimeException('L’option --snapshot est obligatoire avec --execute.');
        }

        $path = $this->absolutePath($path);
        if (file_exists($path)) {
            throw new RuntimeException('Le fichier snapshot existe déjà : refus de l’écraser.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function validatePlan(array $plan): void
    {
        if (($plan['version'] ?? null) !== 1
            || ! is_array($plan['expected'] ?? null)
            || ! is_array($plan['media_repoints'] ?? null)
            || ! is_array($plan['journal_repoints'] ?? null)) {
            throw new RuntimeException('Plan JSON invalide ou version non prise en charge.');
        }

        foreach (['media_rows', 'journal_rows', 'published_documents', 'draft_documents'] as $key) {
            if (! is_int($plan['expected'][$key] ?? null)) {
                throw new RuntimeException("Effectif attendu invalide : {$key}.");
            }
        }

        foreach ($plan['media_repoints'] as $mapping) {
            foreach (['old_object_key', 'target_object_key', 'checksum_sha256', 'file_size', 'media_ids'] as $key) {
                if (! array_key_exists($key, $mapping)) {
                    throw new RuntimeException("Mapping média incomplet : {$key}.");
                }
            }

            if (! is_array($mapping['media_ids']) || $mapping['media_ids'] === []) {
                throw new RuntimeException('Un mapping média ne peut pas avoir un effectif nul.');
            }
        }

        foreach ($plan['journal_repoints'] as $mapping) {
            foreach (['journal_id', 'old_file_path', 'target_object_key', 'checksum_sha256'] as $key) {
                if (! is_string($mapping[$key] ?? null) || $mapping[$key] === '') {
                    throw new RuntimeException("Mapping journal incomplet : {$key}.");
                }
            }
        }

        $plannedMediaRows = collect($plan['media_repoints'])->sum(fn (array $mapping): int => count($mapping['media_ids']));
        if ($plannedMediaRows !== $plan['expected']['media_rows']
            || count($plan['journal_repoints']) !== $plan['expected']['journal_rows']) {
            throw new RuntimeException('Les effectifs du plan ne correspondent pas à son contenu.');
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fichier introuvable : {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("JSON invalide : {$path}.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $snapshot */
    private function writeSnapshot(string $path, array $snapshot): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier du snapshot : {$directory}.");
        }

        file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }

    private function absolutePath(string $path): string
    {
        return Str::startsWith($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function s3Path(string $objectKey): string
    {
        return 's3://mibeko-documents/'.$objectKey;
    }

    /** @param array<string, mixed> $plan */
    private function targetObjectKeyForMedia(array $plan, string $mediaId): string
    {
        foreach ($plan['media_repoints'] as $mapping) {
            if (in_array($mediaId, $mapping['media_ids'], true)) {
                return $mapping['target_object_key'];
            }
        }

        throw new RuntimeException("Média absent du plan : {$mediaId}.");
    }

    /** @param array<string, mixed> $plan */
    private function targetObjectKeyForJournal(array $plan, string $journalId): string
    {
        foreach ($plan['journal_repoints'] as $mapping) {
            if ($journalId === $mapping['journal_id']) {
                return $mapping['target_object_key'];
            }
        }

        throw new RuntimeException("Journal absent du plan : {$journalId}.");
    }
}
