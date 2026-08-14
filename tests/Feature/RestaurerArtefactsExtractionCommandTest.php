<?php

use App\Models\LegalDocument;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

function extractionArtifactRestoreTemporaryDirectory(): string
{
    return sys_get_temp_dir().'/mibeko-extraction-restore-'.str()->uuid();
}

/**
 * @return array{plan:string,snapshot:string,source_root:string,documents:list<LegalDocument>,media:list<MediaFile>,keys:list<string>,paths:list<string>}
 */
function extractionArtifactRestoreFixture(): array
{
    Storage::fake('s3');
    $root = extractionArtifactRestoreTemporaryDirectory();
    mkdir($root.'/pipeline/json', 0755, true);
    mkdir($root.'/pipeline/md', 0755, true);

    $published = LegalDocument::factory()->create(['curation_status' => 'published']);
    $draft = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $documents = [$published, $draft];
    $entries = [
        [
            'name' => 'congo-jo-2024-01.json',
            'source_path' => 'pipeline/json/congo-jo-2024-01.json',
            'content' => json_encode(['pages' => [['page' => 1, 'text' => 'Article premier']]], JSON_THROW_ON_ERROR),
            'category' => 'EXTRACTION_JSON',
            'mime_type' => 'application/json',
            'documents' => [$published, $draft],
        ],
        [
            'name' => 'congo-jo-2024-01.md',
            'source_path' => 'pipeline/md/congo-jo-2024-01.md',
            'content' => "[[MIBEKO_PAGE:1]]\n# Journal officiel\n\nArticle premier.\n",
            'category' => 'EXTRACTION_MARKDOWN',
            'mime_type' => 'text/markdown',
            'documents' => [$published],
        ],
    ];
    $keys = [];
    $paths = [];
    $media = [];
    $planObjects = [];
    $totalBytes = 0;
    $totalReferences = 0;

    foreach ($entries as $index => $entry) {
        $absoluteSourcePath = $root.'/'.$entry['source_path'];
        file_put_contents($absoluteSourcePath, $entry['content']);
        $categoryPath = $entry['category'] === 'EXTRACTION_JSON' ? 'json' : 'markdown';
        $objectKey = "domino/legal-documents/flux/00000000-0000-4000-8000-00000000000{$index}/extractions/{$categoryPath}/{$entry['name']}";

        foreach ($entry['documents'] as $document) {
            $media[] = MediaFile::create([
                'document_id' => $document->id,
                'file_path' => 's3://mibeko-documents/'.$objectKey,
                'storage_provider' => 'MINIO',
                'bucket_name' => 'mibeko-documents',
                'object_key' => $objectKey,
                'original_filename' => $entry['name'],
                'mime_type' => $entry['mime_type'],
                'file_category' => $entry['category'],
                'file_size' => strlen($entry['content']),
                'checksum_sha256' => hash('sha256', $entry['content']),
            ]);
        }

        $references = count($entry['documents']);
        $publishedDocuments = collect($entry['documents'])->where('curation_status', 'published')->count();
        $draftDocuments = collect($entry['documents'])->where('curation_status', 'draft')->count();
        $keys[] = $objectKey;
        $paths[] = $absoluteSourcePath;
        $totalBytes += strlen($entry['content']);
        $totalReferences += $references;
        $planObjects[] = [
            'object_key' => $objectKey,
            'source_path' => $entry['source_path'],
            'mime_type' => $entry['mime_type'],
            'file_category' => $entry['category'],
            'checksum_sha256' => hash('sha256', $entry['content']),
            'file_size' => strlen($entry['content']),
            'references' => $references,
            'documents' => $references,
            'published_documents' => $publishedDocuments,
            'draft_documents' => $draftDocuments,
        ];
    }

    $plan = [
        'version' => 1,
        'bucket' => 'mibeko-documents',
        'expected' => [
            'object_rows' => 2,
            'media_references' => $totalReferences,
            'documents' => 2,
            'published_documents' => 1,
            'draft_documents' => 1,
            'markdown_objects' => 1,
            'json_objects' => 1,
            'total_bytes' => $totalBytes,
        ],
        'objects' => $planObjects,
    ];
    $planPath = $root.'/plan.json';
    $snapshotPath = $root.'/snapshot.json';
    file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));

    return [
        'plan' => $planPath,
        'snapshot' => $snapshotPath,
        'source_root' => $root,
        'documents' => $documents,
        'media' => $media,
        'keys' => $keys,
        'paths' => $paths,
    ];
}

/**
 * @param  array{plan:string,snapshot:string,source_root:string,paths:list<string>}  $fixture
 */
function cleanupExtractionArtifactRestoreFixture(array $fixture): void
{
    @unlink($fixture['plan']);
    @unlink($fixture['snapshot']);
    foreach ($fixture['paths'] as $path) {
        @unlink($path);
    }
    @rmdir($fixture['source_root'].'/pipeline/json');
    @rmdir($fixture['source_root'].'/pipeline/md');
    @rmdir($fixture['source_root'].'/pipeline');
    @rmdir($fixture['source_root']);
}

it('simule, restaure par lot, reprend et annule uniquement les artefacts ajoutés', function () {
    $fixture = extractionArtifactRestoreFixture();

    try {
        $arguments = [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ];

        $this->artisan('mibeko:restaurer-artefacts-extraction', $arguments)
            ->expectsOutputToContain('Plan validé : 2 artefact(s), 2 à restaurer, 0 déjà présent(s), 3 référence(s) média.')
            ->expectsOutputToContain('DRY-RUN OK')
            ->assertSuccessful();

        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            ...$arguments,
            '--limit' => 1,
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($fixture['keys'][0]);
        Storage::disk('s3')->assertMissing($fixture['keys'][1]);

        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            ...$arguments,
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($fixture['keys']);
        $snapshot = json_decode((string) file_get_contents($fixture['snapshot']), true, flags: JSON_THROW_ON_ERROR);
        expect($snapshot['objects_absent_before'])->toHaveCount(2)
            ->and($snapshot['created_objects'])->toHaveCount(2)
            ->and($fixture['media'][0]->fresh()->file_path)->toBe('s3://mibeko-documents/'.$fixture['keys'][0]);

        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            ...$arguments,
            '--rollback' => $fixture['snapshot'],
        ])
            ->expectsOutputToContain('ROLLBACK DRY-RUN OK')
            ->assertSuccessful();

        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            ...$arguments,
            '--rollback' => $fixture['snapshot'],
            '--execute' => true,
        ])->assertSuccessful();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupExtractionArtifactRestoreFixture($fixture);
    }
});

it('refuse une source locale dont le SHA-256 a changé', function () {
    $fixture = extractionArtifactRestoreFixture();
    $content = (string) file_get_contents($fixture['paths'][0]);
    file_put_contents($fixture['paths'][0], str_replace('Article premier', 'Article dernier', $content));

    try {
        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('SHA-256 local inattendu')
            ->assertFailed();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupExtractionArtifactRestoreFixture($fixture);
    }
});

it('refuse de remplacer un objet existant incompatible', function () {
    $fixture = extractionArtifactRestoreFixture();
    Storage::disk('s3')->put($fixture['keys'][0], 'objet étranger');

    try {
        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('Objet existant incompatible')
            ->assertFailed();

        expect(Storage::disk('s3')->get($fixture['keys'][0]))->toBe('objet étranger');
        Storage::disk('s3')->assertMissing($fixture['keys'][1]);
    } finally {
        cleanupExtractionArtifactRestoreFixture($fixture);
    }
});

it('refuse toute exécution avec le profil MinIO de lecture seule', function () {
    $fixture = extractionArtifactRestoreFixture();
    Storage::fake('s3_prod_ro');

    try {
        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
            '--storage' => 's3_prod_ro',
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])
            ->expectsOutputToContain('Exécution refusée sur un stockage en lecture seule')
            ->assertFailed();

        expect(is_file($fixture['snapshot']))->toBeFalse();
    } finally {
        cleanupExtractionArtifactRestoreFixture($fixture);
    }
});

it('refuse une référence média qui a dérivé depuis la mesure', function () {
    $fixture = extractionArtifactRestoreFixture();
    $fixture['media'][0]->update(['mime_type' => 'application/octet-stream']);

    try {
        $this->artisan('mibeko:restaurer-artefacts-extraction', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('Métadonnées média inattendues')
            ->assertFailed();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupExtractionArtifactRestoreFixture($fixture);
    }
});
