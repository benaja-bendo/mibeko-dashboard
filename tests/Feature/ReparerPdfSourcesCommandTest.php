<?php

use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\OfficialJournal;
use Illuminate\Support\Facades\Storage;

function pdfRepairTemporaryPath(string $suffix): string
{
    return sys_get_temp_dir().'/mibeko-pdf-repair-'.str()->uuid().$suffix;
}

/**
 * @return array{plan:string,snapshot:string,old_key:string,target_key:string,media_ids:list<string>,journal:OfficialJournal}
 */
function pdfRepairFixture(): array
{
    Storage::fake('s3');
    $content = 'PDF officiel identique';
    $oldKey = 'documents/flux/ancien/source.pdf';
    $targetKey = 'documents/flux/canonique/source.pdf';
    Storage::disk('s3')->put($targetKey, $content);

    $published = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);
    $draft = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_DRAFT]);
    $mediaIds = collect([$published, $draft])->map(function (LegalDocument $document) use ($content, $oldKey): string {
        return MediaFile::create([
            'document_id' => $document->id,
            'file_path' => 's3://mibeko-documents/'.$oldKey,
            'storage_provider' => 'MINIO',
            'bucket_name' => 'mibeko-documents',
            'object_key' => $oldKey,
            'original_filename' => 'source.pdf',
            'mime_type' => 'application/pdf',
            'file_category' => 'SOURCE_PDF',
            'file_size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
        ])->id;
    })->all();

    $journal = OfficialJournal::factory()->create([
        'file_path' => 's3://mibeko-documents/documents/journaux/ancien.pdf',
    ]);

    $plan = [
        'version' => 1,
        'expected' => [
            'media_rows' => 2,
            'journal_rows' => 1,
            'published_documents' => 1,
            'draft_documents' => 1,
        ],
        'media_repoints' => [[
            'old_object_key' => $oldKey,
            'target_object_key' => $targetKey,
            'checksum_sha256' => hash('sha256', $content),
            'file_size' => strlen($content),
            'media_ids' => $mediaIds,
        ]],
        'journal_repoints' => [[
            'journal_id' => $journal->id,
            'old_file_path' => $journal->file_path,
            'target_object_key' => $targetKey,
            'checksum_sha256' => hash('sha256', $content),
        ]],
    ];

    $planPath = pdfRepairTemporaryPath('.json');
    $snapshotPath = pdfRepairTemporaryPath('-rollback.json');
    file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));

    return [
        'plan' => $planPath,
        'snapshot' => $snapshotPath,
        'old_key' => $oldKey,
        'target_key' => $targetKey,
        'media_ids' => $mediaIds,
        'journal' => $journal,
    ];
}

it('simule, applique et annule un repointage de PDF sans toucher au stockage', function () {
    $fixture = pdfRepairFixture();

    try {
        $this->artisan('mibeko:reparer-pdf-sources', ['plan' => $fixture['plan']])
            ->expectsOutputToContain('Plan validé : 2 références média (1 publiées, 1 brouillons) et 1 journaux.')
            ->expectsOutputToContain('DRY-RUN OK')
            ->assertSuccessful();

        expect(MediaFile::whereIn('id', $fixture['media_ids'])->where('object_key', $fixture['old_key'])->count())->toBe(2);

        $this->artisan('mibeko:reparer-pdf-sources', [
            'plan' => $fixture['plan'],
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])->assertSuccessful();

        expect(MediaFile::whereIn('id', $fixture['media_ids'])->where('object_key', $fixture['target_key'])->count())->toBe(2)
            ->and($fixture['journal']->fresh()->file_path)->toBe('s3://mibeko-documents/'.$fixture['target_key'])
            ->and(is_file($fixture['snapshot']))->toBeTrue();
        Storage::disk('s3')->assertExists($fixture['target_key']);

        $this->artisan('mibeko:reparer-pdf-sources', [
            'plan' => $fixture['plan'],
            '--rollback' => $fixture['snapshot'],
        ])
            ->expectsOutputToContain('ROLLBACK DRY-RUN OK')
            ->assertSuccessful();

        $this->artisan('mibeko:reparer-pdf-sources', [
            'plan' => $fixture['plan'],
            '--rollback' => $fixture['snapshot'],
            '--execute' => true,
        ])->assertSuccessful();

        expect(MediaFile::whereIn('id', $fixture['media_ids'])->where('object_key', $fixture['old_key'])->count())->toBe(2)
            ->and($fixture['journal']->fresh()->file_path)->toBe('s3://mibeko-documents/documents/journaux/ancien.pdf');
        Storage::disk('s3')->assertExists($fixture['target_key']);
    } finally {
        @unlink($fixture['plan']);
        @unlink($fixture['snapshot']);
    }
});

it('refuse toute correction si le PDF cible ne correspond pas au SHA-256 annoncé', function () {
    $fixture = pdfRepairFixture();
    Storage::disk('s3')->put($fixture['target_key'], 'contenu altéré');

    try {
        $this->artisan('mibeko:reparer-pdf-sources', ['plan' => $fixture['plan']])
            ->expectsOutputToContain('SHA-256 inattendu')
            ->assertFailed();

        expect(MediaFile::whereIn('id', $fixture['media_ids'])->where('object_key', $fixture['old_key'])->count())->toBe(2);
    } finally {
        @unlink($fixture['plan']);
    }
});

it('refuse un état partiel où la clé et le chemin du média divergent', function () {
    $fixture = pdfRepairFixture();
    MediaFile::whereKey($fixture['media_ids'][0])->update([
        'file_path' => 's3://mibeko-documents/chemin-inattendu.pdf',
    ]);

    try {
        $this->artisan('mibeko:reparer-pdf-sources', ['plan' => $fixture['plan']])
            ->expectsOutputToContain('Chemin ou clé média inattendu')
            ->assertFailed();

        expect(MediaFile::whereIn('id', $fixture['media_ids'])->where('object_key', $fixture['old_key'])->count())->toBe(2);
    } finally {
        @unlink($fixture['plan']);
    }
});
