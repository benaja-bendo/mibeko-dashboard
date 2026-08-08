<?php

use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `mibeko:operations` — file d'opérations Classe 1 (production.md § 6 bis) :
 * lots validés y/n dans le terminal, liste blanche stricte (curation de
 * staging uniquement), arrêt net sur écart d'effectif.
 */
function cheminFileOperations(string $etat = ''): string
{
    return storage_path('app/operations'.($etat !== '' ? "/{$etat}" : ''));
}

/**
 * @param  array<string, mixed>  $lot
 */
function deposerLotOperations(array $lot, string $nom = 'lot-test.json'): string
{
    File::ensureDirectoryExists(cheminFileOperations('pending'));
    $chemin = cheminFileOperations('pending')."/{$nom}";
    File::put($chemin, json_encode($lot, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

/**
 * Enveloppe conforme au § 6 bis autour d'une opération et de ses paramètres.
 *
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function lotClasseUne(string $operation, array $params, int $attendues): array
{
    return [
        'operation' => $operation,
        'params' => $params,
        'expected_rows' => $attendues,
        'rollback' => [
            'description' => 'Opération inverse sur les mêmes identifiants.',
            'moyen' => 'Lot inverse préparé dans la même campagne.',
        ],
        'dry_run_output' => "{$attendues} ligne(s) seraient touchées (dry-run sur copie restaurée).",
        'campagne' => 'test-classe-1',
    ];
}

function signalementOuvert(LegalDocument $document): CurationFlag
{
    return CurationFlag::create([
        'document_id' => $document->id,
        'source' => CurationFlag::SOURCE_HEURISTIC,
        'type_probleme' => 'numerotation',
        'severity' => CurationFlag::SEVERITY_WARNING,
        'description' => 'Trou de numérotation détecté.',
        'resolved' => false,
    ]);
}

beforeEach(function () {
    File::deleteDirectory(cheminFileOperations());

    Permission::findOrCreate('documents.update');
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('documents.update');

    $this->operateur = User::factory()->create();
    $this->operateur->assignRole('editor');

    putenv('MIBEKO_API_TOKEN='.$this->operateur->createToken('operations')->plainTextToken);
});

afterEach(function () {
    putenv('MIBEKO_API_TOKEN');
    File::deleteDirectory(cheminFileOperations());
});

// ── Lots valides ─────────────────────────────────────────────────────────────

it('exécute un lot valide après y et archive le rapport dans done/', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $flags = collect([signalementOuvert($document), signalementOuvert($document)]);

    deposerLotOperations(lotClasseUne('resoudre_signalements', ['ids' => $flags->pluck('id')->all()], 2));

    $this->artisan('mibeko:operations')
        ->expectsConfirmation('Exécuter ce lot ?', 'yes')
        ->assertSuccessful();

    expect(CurationFlag::where('resolved', true)->count())->toBe(2)
        ->and(CurationFlag::query()->first()->resolved_by)->toBe($this->operateur->id)
        ->and(File::glob(cheminFileOperations('pending').'/*.json'))->toBeEmpty();

    $archives = File::glob(cheminFileOperations('done').'/*.json');
    expect($archives)->toHaveCount(1);

    $rapport = json_decode(File::get($archives[0]), true)['rapport'];
    expect($rapport['lignes_touchees'])->toBe(2)
        ->and($rapport['utilisateur']['id'])->toBe($this->operateur->id)
        ->and($rapport['connexion'])->toBe('pgsql');
});

it('applique une transition de staging via la machine à états, auditée et attribuée', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    deposerLotOperations(lotClasseUne('changer_statut_documents', [
        'document_ids' => [$document->id],
        'vers' => 'review',
    ], 1));

    $this->artisan('mibeko:operations')
        ->expectsConfirmation('Exécuter ce lot ?', 'yes')
        ->assertSuccessful();

    expect($document->fresh()->curation_status)->toBe('review');

    $audit = $document->audits()->where('event', 'updated')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->getModified()['curation_status']['new'] ?? null)->toBe('review')
        ->and($audit->user_id)->toBe($this->operateur->id);
});

// ── Liste blanche ────────────────────────────────────────────────────────────

it('rejette sans prompt un lot dont l\'opération est hors liste blanche', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    deposerLotOperations(lotClasseUne('publier_documents', ['document_ids' => [$document->id]], 1));

    $this->artisan('mibeko:operations')
        ->expectsOutputToContain('hors liste blanche')
        ->assertSuccessful();

    expect($document->fresh()->curation_status)->toBe('draft');

    $rejets = File::glob(cheminFileOperations('rejected').'/*.json');
    expect($rejets)->toHaveCount(1);
    expect(implode(' ', json_decode(File::get($rejets[0]), true)['rejet']['motifs']))
        ->toContain('hors liste blanche');
});

it('rejette un lot qui viserait le statut published', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'validated']);

    deposerLotOperations(lotClasseUne('changer_statut_documents', [
        'document_ids' => [$document->id],
        'vers' => 'published',
    ], 1));

    $this->artisan('mibeko:operations')->assertSuccessful();

    expect($document->fresh()->curation_status)->toBe('validated')
        ->and(File::glob(cheminFileOperations('rejected').'/*.json'))->toHaveCount(1);
});

it('rejette un lot qui toucherait un document publié', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    deposerLotOperations(lotClasseUne('modifier_metadonnees_documents', [
        'modifications' => [['id' => $document->id, 'champs' => ['titre_officiel' => 'Nouveau titre']]],
    ], 1));

    $this->artisan('mibeko:operations')
        ->expectsOutputToContain('Classe 2')
        ->assertSuccessful();

    expect($document->fresh()->titre_officiel)->not->toBe('Nouveau titre')
        ->and(File::glob(cheminFileOperations('rejected').'/*.json'))->toHaveCount(1);
});

it('rejette un lot qui toucherait un document AYANT ÉTÉ publié (trace d\'audit)', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $document->audits()->create([
        'event' => 'updated',
        'old_values' => ['curation_status' => 'review'],
        'new_values' => ['curation_status' => 'published'],
    ]);

    deposerLotOperations(lotClasseUne('changer_statut_documents', [
        'document_ids' => [$document->id],
        'vers' => 'review',
    ], 1));

    $this->artisan('mibeko:operations')
        ->expectsOutputToContain('a déjà été publié')
        ->assertSuccessful();

    expect($document->fresh()->curation_status)->toBe('draft')
        ->and(File::glob(cheminFileOperations('rejected').'/*.json'))->toHaveCount(1);
});

it('rejette une modification de métadonnées dont un champ est hors liste blanche', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    deposerLotOperations(lotClasseUne('modifier_metadonnees_documents', [
        'modifications' => [['id' => $document->id, 'champs' => ['curation_status' => 'published']]],
    ], 1));

    $this->artisan('mibeko:operations')
        ->expectsOutputToContain('hors liste blanche')
        ->assertSuccessful();

    expect($document->fresh()->curation_status)->toBe('draft')
        ->and(File::glob(cheminFileOperations('rejected').'/*.json'))->toHaveCount(1);
});

// ── Validation au clavier ────────────────────────────────────────────────────

it('déplace le lot vers rejected/ sur un n, sans rien écrire', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $flag = signalementOuvert($document);

    deposerLotOperations(lotClasseUne('resoudre_signalements', ['ids' => [$flag->id]], 1));

    $this->artisan('mibeko:operations')
        ->expectsConfirmation('Exécuter ce lot ?', 'no')
        ->assertSuccessful();

    expect($flag->fresh()->resolved)->toBeFalse()
        ->and(File::glob(cheminFileOperations('pending').'/*.json'))->toBeEmpty()
        ->and(File::glob(cheminFileOperations('rejected').'/*.json'))->toHaveCount(1);
});

// ── Écart d'effectif ─────────────────────────────────────────────────────────

it('s\'arrête net quand l\'effectif touché dévie de l\'annoncé, sans conserver d\'écriture', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $flags = collect([signalementOuvert($document), signalementOuvert($document)]);

    // Le lot annonce 5 lignes, la base n'en offre que 2 : incident.
    deposerLotOperations(lotClasseUne('resoudre_signalements', ['ids' => $flags->pluck('id')->all()], 5));

    $this->artisan('mibeko:operations')
        ->expectsConfirmation('Exécuter ce lot ?', 'yes')
        ->expectsOutputToContain('INCIDENT')
        ->assertFailed();

    expect(CurationFlag::where('resolved', true)->count())->toBe(0)
        ->and(File::glob(cheminFileOperations('pending').'/*.json'))->toHaveCount(1)
        ->and(File::glob(cheminFileOperations('done').'/*.json'))->toBeEmpty();
});

// ── Prérequis de session ─────────────────────────────────────────────────────

it('refuse de démarrer sans MIBEKO_API_TOKEN', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:operations')
        ->expectsOutputToContain('MIBEKO_API_TOKEN')
        ->assertFailed();
});

it('refuse un jeton dont le compte a été supprimé', function () {
    $jeton = $this->operateur->createToken('operations-supprime')->plainTextToken;
    putenv("MIBEKO_API_TOKEN={$jeton}");
    $this->operateur->delete();

    $this->artisan('mibeko:operations')->assertFailed();
});
