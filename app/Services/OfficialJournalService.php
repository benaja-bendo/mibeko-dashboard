<?php

namespace App\Services;

use App\Models\OfficialJournal;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfficialJournalService
{
    /**
     * Upload a new Official Journal PDF to MinIO and create the record.
     */
    public function uploadAndCreate(array $data, UploadedFile $file): OfficialJournal
    {
        $disk = config('filesystems.default', 'local');

        $filename = Str::slug($data['title']).'_'.time().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('official_journals', $filename, $disk);

        if (! $path) {
            throw new \Exception("Échec de l'upload du fichier sur le disque: {$disk}");
        }

        return OfficialJournal::create([
            'title' => $data['title'],
            'publication_date' => $data['publication_date'] ?? null,
            'file_path' => $path,
            'transcription_status' => OfficialJournal::STATUS_PENDING,
            'is_published' => $data['is_published'] ?? false,
        ]);
    }

    /**
     * Update an Official Journal, optionally replacing the file.
     */
    public function updateJournal(OfficialJournal $journal, array $data, ?UploadedFile $file): bool
    {
        $disk = config('filesystems.default', 'local');
        $updateData = [
            'title' => $data['title'],
            'publication_date' => $data['publication_date'] ?? null,
            'is_published' => $data['is_published'] ?? false,
        ];

        if ($file) {
            // Delete old file if exists
            if ($journal->file_path && Storage::disk($disk)->exists($journal->file_path)) {
                Storage::disk($disk)->delete($journal->file_path);
            }

            $filename = Str::slug($data['title']).'_'.time().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('official_journals', $filename, $disk);
            $updateData['file_path'] = $path;

            // If a new file is uploaded, reset transcription status
            $updateData['transcription_status'] = OfficialJournal::STATUS_PENDING;
        }

        return $journal->update($updateData);
    }

    /**
     * Update transcription status.
     */
    public function updateTranscriptionStatus(OfficialJournal $journal, string $status): bool
    {
        return $journal->update([
            'transcription_status' => $status,
        ]);
    }

    /**
     * Get the temporary URL to download or view the PDF from MinIO.
     */
    public function getFileUrl(OfficialJournal $journal): ?string
    {
        if (! $journal->file_path) {
            return null;
        }

        $disk = config('filesystems.default', 'local');

        /** @var FilesystemAdapter $storageDisk */
        $storageDisk = Storage::disk($disk);

        if ($storageDisk->exists($journal->file_path)) {
            // If it's an S3 disk (MinIO), we can generate a temporary URL.
            // For local, we can just return the URL.
            if ($disk === 's3') {
                return $storageDisk->temporaryUrl(
                    $journal->file_path,
                    now()->addMinutes(60)
                );
            }

            return $storageDisk->url($journal->file_path);
        }

        return null;
    }
}
