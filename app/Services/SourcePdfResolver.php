<?php

namespace App\Services;

use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\OfficialJournal;
use Illuminate\Support\Facades\Storage;

final class SourcePdfResolver
{
    public function __construct(private readonly MediaStorageLocator $storageLocator) {}

    public function forDocument(LegalDocument $document): ?ResolvedSourcePdf
    {
        $document->loadMissing(['mediaFiles', 'officialJournal']);

        $sourcePdfMedia = $document->mediaFiles
            ->filter(fn (MediaFile $media): bool => $this->isPdf($media))
            ->sortByDesc(fn (MediaFile $media): bool => $media->file_category === 'SOURCE_PDF');

        foreach ($sourcePdfMedia as $media) {
            $resolved = $this->resolvePaths(
                $media->storage_provider,
                $media->object_key,
                $media->file_path,
            );

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $document->officialJournal === null
            ? null
            : $this->forOfficialJournal($document->officialJournal);
    }

    public function forOfficialJournal(OfficialJournal $journal): ?ResolvedSourcePdf
    {
        return $this->resolvePaths(
            null,
            null,
            $journal->file_path,
        );
    }

    private function isPdf(MediaFile $media): bool
    {
        if ($media->file_category === 'SOURCE_PDF' || $media->mime_type === 'application/pdf') {
            return true;
        }

        return collect($this->storageLocator->objectKeys($media->object_key, $media->file_path))
            ->contains(fn (string $path): bool => str_ends_with(strtolower($path), '.pdf'));
    }

    private function resolvePaths(?string $storageProvider, ?string $objectKey, ?string $filePath): ?ResolvedSourcePdf
    {
        $diskName = $this->storageLocator->diskName($storageProvider, $filePath ?: $objectKey);
        if ($diskName === null) {
            return null;
        }

        foreach ($this->storageLocator->objectKeys($objectKey, $filePath) as $path) {
            try {
                if (Storage::disk($diskName)->exists($path)) {
                    return new ResolvedSourcePdf($diskName, $path);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }
}
