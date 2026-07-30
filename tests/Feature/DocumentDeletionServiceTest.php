<?php

use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Services\DocumentDeletionService;
use Illuminate\Support\Facades\Storage;

/**
 * Crée un media_file tel que le service Python l'écrit : `storage_provider`
 * MINIO et `file_path` préfixé `s3://<bucket>/`.
 */
function pythonMediaFile(LegalDocument $document, string $objectKey, ?string $overrideObjectKey = null): MediaFile
{
    return MediaFile::create([
        'document_id' => $document->id,
        'file_path' => 's3://mibeko-documents/'.$objectKey,
        'storage_provider' => 'MINIO',
        'bucket_name' => 'mibeko-documents',
        'object_key' => $overrideObjectKey ?? $objectKey,
        'original_filename' => basename($objectKey),
        'mime_type' => 'application/pdf',
        'file_category' => 'SOURCE_PDF',
        'file_size' => 1024,
        'checksum_sha256' => str_repeat('a', 64),
    ]);
}

it('purge l’objet MinIO d’un media_file créé par le service Python', function () {
    Storage::fake('s3');
    $key = 'documents/stock/source/pdf/loi-2020-01.pdf';
    Storage::disk('s3')->put($key, 'PDF');

    $document = LegalDocument::factory()->create();
    pythonMediaFile($document, $key);

    app(DocumentDeletionService::class)->forceDelete($document);

    Storage::disk('s3')->assertMissing($key);
    expect(MediaFile::where('document_id', $document->id)->count())->toBe(0)
        ->and(LegalDocument::withTrashed()->find($document->id))->toBeNull();
});

it('retombe sur file_path en retirant le préfixe s3://bucket quand object_key est vide', function () {
    Storage::fake('s3');
    $key = 'documents/flux/extractions/markdown/acte.md';
    Storage::disk('s3')->put($key, '# acte');

    $document = LegalDocument::factory()->create();
    pythonMediaFile($document, $key, overrideObjectKey: '');

    app(DocumentDeletionService::class)->forceDelete($document);

    Storage::disk('s3')->assertMissing($key);
});

it('laisse la suppression en base aboutir quand le fournisseur de stockage est inconnu', function () {
    Storage::fake('s3');
    $key = 'documents/stock/source/pdf/autre.pdf';
    Storage::disk('s3')->put($key, 'PDF');

    $document = LegalDocument::factory()->create();
    pythonMediaFile($document, $key)->update(['storage_provider' => 'GLACIER']);

    app(DocumentDeletionService::class)->forceDelete($document);

    // Disque inconnu : on journalise et on continue, sans toucher au bucket S3
    // ni empêcher la suppression du document.
    Storage::disk('s3')->assertExists($key);
    expect(LegalDocument::withTrashed()->find($document->id))->toBeNull();
});
