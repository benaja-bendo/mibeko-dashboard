<?php

use App\Models\IngestionJob;
use App\Models\IngestionProvenance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * mibeko-python#22 / mibeko-dashboard#140 — fondations de la file durable.
 *
 * Comme `PublicationConcurrencyTest.php` (dashboard#119) : `RefreshDatabase`
 * enveloppe chaque test dans une transaction sur la connexion Laravel, donc
 * une SECONDE connexion PDO brute ne voit pas ses lignes non commitées
 * (MVCC Postgres). La ligne testée ici est insérée et commitée hors de cette
 * transaction (autocommit PDO), pour qu'une vraie course entre deux
 * connexions se rejoue fidèlement.
 *
 * Ce lot ne livre que la migration et le modèle minimal : la logique de
 * réservation (bail, fencing_token) vit côté worker Python
 * (mibeko-python#23). Ce test prouve que la TABLE supporte bien le primitif
 * dont ce worker dépendra — `SELECT … FOR UPDATE SKIP LOCKED` — pas que ce
 * worker existe déjà.
 */
uses(RefreshDatabase::class);

function openRawIngestionConnection(): PDO
{
    $config = config('database.connections.pgsql');

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
}

it('applique les valeurs par défaut attendues par le worker', function () {
    $job = IngestionJob::create(['kind' => IngestionJob::KIND_DEPOT]);

    expect($job->step)->toBe(IngestionJob::STEP_RECU)
        ->and($job->status)->toBe(IngestionJob::STATUS_PENDING)
        ->and($job->attempts)->toBe(0)
        ->and($job->max_attempts)->toBe(3)
        ->and($job->fencing_token)->toBe(0)
        ->and($job->result)->toBe([]);
});

it('refuse un kind hors de la liste blanche (contrainte DB, pas seulement applicative)', function () {
    IngestionJob::create(['kind' => 'inconnu']);
})->throws(QueryException::class);

it('refuse un step hors de la liste blanche', function () {
    IngestionJob::create(['kind' => IngestionJob::KIND_DEPOT, 'step' => 'inconnu']);
})->throws(QueryException::class);

it('refuse un status hors de la liste blanche', function () {
    IngestionJob::create(['kind' => IngestionJob::KIND_DEPOT, 'status' => 'inconnu']);
})->throws(QueryException::class);

it('refuse un error_class hors de la liste blanche, mais accepte null', function () {
    IngestionJob::create(['kind' => IngestionJob::KIND_DEPOT, 'error_class' => 'inconnu']);
})->throws(QueryException::class);

it('accepte error_class null (aucune erreur)', function () {
    $job = IngestionJob::create(['kind' => IngestionJob::KIND_DEPOT]);

    expect($job->error_class)->toBeNull();
});

it(
    'SELECT … FOR UPDATE SKIP LOCKED laisse une seconde session reprendre un AUTRE travail '.
    'plutôt que d\'attendre — le primitif exact dont le worker Python (mibeko-python#23) dépendra',
    function () {
        $connA = openRawIngestionConnection();
        $connB = openRawIngestionConnection();

        // Deux travaux insérés et commités hors de la transaction de test
        // (autocommit PDO), donc réellement visibles des deux connexions.
        $insert = $connA->prepare(
            "insert into ingestion_jobs (id, kind) values (gen_random_uuid(), 'depot') returning id"
        );
        $insert->execute();
        $jobUnId = $insert->fetchColumn();
        $insert->execute();
        $jobDeuxId = $insert->fetchColumn();

        try {
            // A verrouille le premier travail disponible et ne relâche pas encore.
            $connA->beginTransaction();
            $connA->query('select id from ingestion_jobs order by created_at for update skip locked limit 1');

            // B, avec la même requête, ne doit PAS attendre : il saute la ligne
            // verrouillée par A et récupère l'AUTRE travail.
            $connB->beginTransaction();
            $stmtB = $connB->query('select id from ingestion_jobs order by created_at for update skip locked limit 1');
            $reserveParB = $stmtB->fetchColumn();

            expect($reserveParB)->not->toBe($jobUnId);
            expect(in_array($reserveParB, [$jobUnId, $jobDeuxId], true))->toBeTrue();

            $connA->commit();
            $connB->commit();
        } finally {
            // Lignes réellement commitées : le rollback de RefreshDatabase en
            // fin de test ne les nettoiera pas.
            $connA->exec('delete from ingestion_jobs where id in ('.$connA->quote($jobUnId).', '.$connA->quote($jobDeuxId).')');
        }
    }
);

it('ingestion_provenances refuse un manifest_id en doublon (dédoublonnage à la source)', function () {
    IngestionProvenance::create([
        'manifest_id' => 'sgg-jo/congo-jo-2026-13',
        'type_source' => 'journal_officiel',
        'sha256' => str_repeat('a', 64),
        'evenements' => [],
    ]);

    IngestionProvenance::create([
        'manifest_id' => 'sgg-jo/congo-jo-2026-13',
        'type_source' => 'journal_officiel',
        'sha256' => str_repeat('b', 64),
        'evenements' => [],
    ]);
})->throws(QueryException::class);

it('conserve les événements de provenance sous la même forme que ManifestEntry (quand/quoi/par/detail)', function () {
    $provenance = IngestionProvenance::create([
        'manifest_id' => 'sgg-jo/congo-jo-2026-14',
        'type_source' => 'journal_officiel',
        'source_url' => 'https://sgg.cg/JO/2026/congo-jo-2026-14.pdf',
        'sha256' => str_repeat('c', 64),
        'fetched_at' => now(),
        'evenements' => [
            ['quand' => '2026-09-14T12:00:00+00:00', 'quoi' => 'telecharge', 'par' => 'MibekoBot/acquire', 'detail' => null],
        ],
    ]);

    expect($provenance->fresh()->evenements)->toHaveCount(1)
        ->and($provenance->fresh()->evenements[0]['quoi'])->toBe('telecharge');
});

it(
    'porte les 9 champs de ManifestEntry ajoutés par mibeko-python#28 (fichier, statut, size_bytes, '.
    'jo_numero/jo_date/jo_annee, titre, retroactif, variantes_multiples)',
    function () {
        $provenance = IngestionProvenance::create([
            'manifest_id' => 'sgg-jo/congo-jo-2026-15',
            'type_source' => 'journal_officiel',
            'fichier' => 'sources/sgg-jo/congo-jo-2026-15.pdf',
            'statut' => 'structure',
            'size_bytes' => 4_215_872,
            'source_url' => 'https://sgg.cg/JO/2026/congo-jo-2026-15.pdf',
            'jo_numero' => '15',
            'jo_date' => '2026-04-12',
            'jo_annee' => 2026,
            'titre' => 'Journal officiel n° 15 du 12 avril 2026',
            'sha256' => str_repeat('d', 64),
            'retroactif' => false,
            'variantes_multiples' => ['sgg-jo/congo-jo-2026-15-bis'],
            'evenements' => [],
        ]);

        $fresh = $provenance->fresh();

        expect($fresh->fichier)->toBe('sources/sgg-jo/congo-jo-2026-15.pdf')
            ->and($fresh->statut)->toBe('structure')
            ->and($fresh->size_bytes)->toBe(4_215_872)
            ->and($fresh->jo_numero)->toBe('15')
            ->and($fresh->jo_date->toDateString())->toBe('2026-04-12')
            ->and($fresh->jo_annee)->toBe(2026)
            ->and($fresh->titre)->toBe('Journal officiel n° 15 du 12 avril 2026')
            ->and($fresh->retroactif)->toBeFalse()
            ->and($fresh->variantes_multiples)->toBe(['sgg-jo/congo-jo-2026-15-bis']);
    }
);

it('accepte une provenance sans aucun des 9 nouveaux champs (lignes déjà écrites avant mibeko-python#28)', function () {
    $provenance = IngestionProvenance::create([
        'manifest_id' => 'depots/deja-existante-avant-28',
        'type_source' => 'acte',
        'sha256' => str_repeat('e', 64),
        'evenements' => [],
    ]);

    $fresh = $provenance->fresh();

    expect($fresh->fichier)->toBeNull()
        ->and($fresh->statut)->toBeNull()
        ->and($fresh->size_bytes)->toBeNull()
        ->and($fresh->retroactif)->toBeFalse()
        ->and($fresh->variantes_multiples)->toBeNull();
});
