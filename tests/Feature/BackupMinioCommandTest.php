<?php

use Illuminate\Support\Facades\Storage;

/**
 * `mibeko:backup-minio` : le corpus (bucket MinIO) n'était couvert par aucune
 * sauvegarde automatique avant cette commande — voir
 * docs/_archive/audit-prod-2026-08-04.md § 8.
 */
it('archive tout le contenu du disque source vers le disque destination', function () {
    Storage::fake('s3');
    Storage::fake('gdrive');

    Storage::disk('s3')->put('sources/loi-1.pdf', 'contenu du pdf 1');
    Storage::disk('s3')->put('sources/loi-2.pdf', 'contenu du pdf 2, un peu plus long');
    Storage::disk('s3')->put('sources/sous-dossier/loi-3.pdf', 'contenu du pdf 3');

    $this->artisan('mibeko:backup-minio')->assertExitCode(0);

    $fichiers = collect(Storage::disk('gdrive')->allFiles())
        ->filter(fn ($f) => str_ends_with($f, '.zip'));

    expect($fichiers)->toHaveCount(1);

    $zip = new ZipArchive;
    $ouvert = $zip->open(Storage::disk('gdrive')->path($fichiers->first()));

    expect($ouvert)->toBeTrue();
    expect($zip->numFiles)->toBe(3);
    expect($zip->getFromName('sources/loi-1.pdf'))->toBe('contenu du pdf 1');
    expect($zip->getFromName('sources/sous-dossier/loi-3.pdf'))->toBe('contenu du pdf 3');

    $zip->close();
});

it('refuse une sauvegarde vers le même disque que la source', function () {
    Storage::fake('s3');

    $this->artisan('mibeko:backup-minio', ['--destination' => 's3'])
        ->assertExitCode(1);
});

it('réussit sans rien envoyer quand le bucket source est vide', function () {
    Storage::fake('s3');
    Storage::fake('gdrive');

    $this->artisan('mibeko:backup-minio')->assertExitCode(0);

    expect(Storage::disk('gdrive')->allFiles())->toBeEmpty();
});

it('restreint la sauvegarde au préfixe demandé', function () {
    Storage::fake('s3');
    Storage::fake('gdrive');

    Storage::disk('s3')->put('sources/loi-1.pdf', 'a');
    Storage::disk('s3')->put('autre/fichier.txt', 'b');

    $this->artisan('mibeko:backup-minio', ['--prefix' => 'sources'])->assertExitCode(0);

    $zip = new ZipArchive;
    $chemin = collect(Storage::disk('gdrive')->allFiles())->first(fn ($f) => str_ends_with($f, '.zip'));
    $zip->open(Storage::disk('gdrive')->path($chemin));

    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('sources/loi-1.pdf'))->toBe('a');

    $zip->close();
});
