<?php

use App\Models\OfficialJournal;
use Illuminate\Support\Facades\Storage;

function journalPdfRestoreTemporaryDirectory(): string
{
    return sys_get_temp_dir().'/mibeko-jo-restore-'.str()->uuid();
}

/**
 * @return array{plan:string,snapshot:string,source_root:string,journals:list<OfficialJournal>,keys:list<string>,paths:list<string>}
 */
function journalPdfRestoreFixture(): array
{
    Storage::fake('s3');
    $root = journalPdfRestoreTemporaryDirectory();
    $sourceDirectory = $root.'/sources/sgg/JO';
    mkdir($sourceDirectory, 0755, true);

    $entries = [
        ['name' => 'congo-jo-2024-01-sp', 'number' => '1', 'date' => '2024-01-26'],
        ['name' => 'congo-jo-2024-05-sp', 'number' => '5', 'date' => '2024-04-04'],
    ];
    $journals = [];
    $keys = [];
    $paths = [];
    $planEntries = [];
    $totalBytes = 0;

    foreach ($entries as $index => $entry) {
        $content = "%PDF-1.7\nsource officielle {$entry['name']}\n%%EOF\n";
        $sourcePath = "sources/sgg/JO/{$entry['name']}.pdf";
        $absoluteSourcePath = $root.'/'.$sourcePath;
        file_put_contents($absoluteSourcePath, $content);

        $objectKey = "domino/legal-documents/flux/00000000-0000-4000-8000-00000000000{$index}/source/pdf/{$entry['name']}.pdf";
        $journal = OfficialJournal::factory()->create([
            'title' => "Journal officiel n° {$entry['number']}-2024",
            'number' => $entry['number'],
            'publication_date' => $entry['date'],
            'file_path' => 's3://mibeko-documents/'.$objectKey,
            'is_published' => true,
        ]);

        $journals[] = $journal;
        $keys[] = $objectKey;
        $paths[] = $absoluteSourcePath;
        $totalBytes += strlen($content);
        $planEntries[] = [
            'journal_id' => $journal->id,
            'title' => $journal->title,
            'number' => $entry['number'],
            'publication_date' => $entry['date'],
            'is_published' => true,
            'living_documents' => 0,
            'object_key' => $objectKey,
            'source_path' => $sourcePath,
            'source_url' => "https://www.sgg.cg/JO/2024/{$entry['name']}.pdf",
            'checksum_sha256' => hash('sha256', $content),
            'file_size' => strlen($content),
        ];
    }

    $plan = [
        'version' => 1,
        'bucket' => 'mibeko-documents',
        'expected' => [
            'journal_rows' => 2,
            'published_journals' => 2,
            'living_documents' => 0,
            'objects_to_restore' => 2,
            'total_bytes' => $totalBytes,
        ],
        'journals' => $planEntries,
    ];
    $planPath = $root.'/plan.json';
    $snapshotPath = $root.'/snapshot.json';
    file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));

    return [
        'plan' => $planPath,
        'snapshot' => $snapshotPath,
        'source_root' => $root,
        'journals' => $journals,
        'keys' => $keys,
        'paths' => $paths,
    ];
}

/**
 * @param  array{plan:string,snapshot:string,source_root:string,paths:list<string>}  $fixture
 */
function cleanupJournalPdfRestoreFixture(array $fixture): void
{
    @unlink($fixture['plan']);
    @unlink($fixture['snapshot']);
    foreach ($fixture['paths'] as $path) {
        @unlink($path);
    }
    @rmdir($fixture['source_root'].'/sources/sgg/JO');
    @rmdir($fixture['source_root'].'/sources/sgg');
    @rmdir($fixture['source_root'].'/sources');
    @rmdir($fixture['source_root']);
}

it('simule, restaure par lot, reprend et annule uniquement les PDF ajoutés', function () {
    $fixture = journalPdfRestoreFixture();

    try {
        $arguments = [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ];

        $this->artisan('mibeko:restaurer-pdf-journaux', $arguments)
            ->expectsOutputToContain('Plan validé : 2 journal(aux), 2 PDF à restaurer, 0 déjà présent(s).')
            ->expectsOutputToContain('DRY-RUN OK')
            ->assertSuccessful();

        $this->artisan('mibeko:restaurer-pdf-journaux', [
            ...$arguments,
            '--limit' => 1,
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($fixture['keys'][0]);
        Storage::disk('s3')->assertMissing($fixture['keys'][1]);

        $this->artisan('mibeko:restaurer-pdf-journaux', [
            ...$arguments,
            '--execute' => true,
            '--snapshot' => $fixture['snapshot'],
        ])->assertSuccessful();

        Storage::disk('s3')->assertExists($fixture['keys']);
        $snapshot = json_decode((string) file_get_contents($fixture['snapshot']), true, flags: JSON_THROW_ON_ERROR);
        expect($snapshot['objects_absent_before'])->toHaveCount(2)
            ->and($snapshot['created_objects'])->toHaveCount(2)
            ->and($fixture['journals'][0]->fresh()->file_path)->toBe('s3://mibeko-documents/'.$fixture['keys'][0]);

        $this->artisan('mibeko:restaurer-pdf-journaux', [
            ...$arguments,
            '--rollback' => $fixture['snapshot'],
        ])
            ->expectsOutputToContain('ROLLBACK DRY-RUN OK')
            ->assertSuccessful();

        $this->artisan('mibeko:restaurer-pdf-journaux', [
            ...$arguments,
            '--rollback' => $fixture['snapshot'],
            '--execute' => true,
        ])->assertSuccessful();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupJournalPdfRestoreFixture($fixture);
    }
});

it('refuse une source locale dont le SHA-256 a changé', function () {
    $fixture = journalPdfRestoreFixture();
    file_put_contents($fixture['paths'][0], "%PDF-1.7\nsource modifiée\n%%EOF\n");

    try {
        $this->artisan('mibeko:restaurer-pdf-journaux', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('Taille locale inattendue')
            ->assertFailed();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupJournalPdfRestoreFixture($fixture);
    }
});

it('refuse de remplacer un objet existant incompatible', function () {
    $fixture = journalPdfRestoreFixture();
    Storage::disk('s3')->put($fixture['keys'][0], 'objet étranger');

    try {
        $this->artisan('mibeko:restaurer-pdf-journaux', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('Objet existant incompatible')
            ->assertFailed();

        expect(Storage::disk('s3')->get($fixture['keys'][0]))->toBe('objet étranger');
        Storage::disk('s3')->assertMissing($fixture['keys'][1]);
    } finally {
        cleanupJournalPdfRestoreFixture($fixture);
    }
});

it('refuse toute exécution avec le profil MinIO de lecture seule', function () {
    $fixture = journalPdfRestoreFixture();
    Storage::fake('s3_prod_ro');

    try {
        $this->artisan('mibeko:restaurer-pdf-journaux', [
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
        cleanupJournalPdfRestoreFixture($fixture);
    }
});

it('refuse une fiche de journal qui a dérivé depuis la mesure', function () {
    $fixture = journalPdfRestoreFixture();
    $fixture['journals'][0]->update(['title' => 'Titre modifié']);

    try {
        $this->artisan('mibeko:restaurer-pdf-journaux', [
            'plan' => $fixture['plan'],
            '--source-root' => $fixture['source_root'],
        ])
            ->expectsOutputToContain('Métadonnées inattendues')
            ->assertFailed();

        Storage::disk('s3')->assertMissing($fixture['keys']);
    } finally {
        cleanupJournalPdfRestoreFixture($fixture);
    }
});
