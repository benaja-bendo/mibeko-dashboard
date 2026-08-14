<?php

namespace App\Services;

use Illuminate\Support\Str;

final class MediaStorageLocator
{
    /** @var array<string, string> */
    private const PROVIDER_DISKS = [
        'MINIO' => 's3',
        'S3' => 's3',
        'LOCAL' => 'local',
        'PUBLIC' => 'public',
    ];

    /**
     * @return list<string>
     */
    public function objectKeys(?string $objectKey, ?string $filePath): array
    {
        return collect([$objectKey, $filePath])
            ->map(fn (?string $path): ?string => $this->normalizeObjectKey($path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function objectKey(?string $objectKey, ?string $filePath): ?string
    {
        return $this->objectKeys($objectKey, $filePath)[0] ?? null;
    }

    public function diskName(?string $storageProvider, ?string $path): ?string
    {
        $provider = trim((string) $storageProvider);

        if ($provider !== '') {
            $disk = self::PROVIDER_DISKS[strtoupper($provider)] ?? $provider;

            return config("filesystems.disks.{$disk}") !== null ? $disk : null;
        }

        $disk = Str::startsWith((string) $path, ['s3://', 'documents/'])
            ? 's3'
            : (string) config('filesystems.default', 'local');

        return config("filesystems.disks.{$disk}") !== null ? $disk : null;
    }

    public function bucketName(?string $bucketName, ?string $filePath, string $diskName): ?string
    {
        $bucket = trim((string) $bucketName);
        if ($bucket !== '') {
            return $bucket;
        }

        if (Str::startsWith((string) $filePath, 's3://')) {
            return explode('/', (string) $filePath, 4)[2] ?? null;
        }

        $configuredBucket = config("filesystems.disks.{$diskName}.bucket");

        return is_string($configuredBucket) && $configuredBucket !== '' ? $configuredBucket : null;
    }

    private function normalizeObjectKey(?string $path): ?string
    {
        $key = trim((string) $path);

        if (Str::startsWith($key, 's3://')) {
            $key = explode('/', $key, 4)[3] ?? '';
        }

        return $key !== '' ? $key : null;
    }
}
