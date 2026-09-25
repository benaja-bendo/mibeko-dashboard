<?php

use App\Models\Article;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\DossierGeneratedDocument;
use App\Models\DossierPiece;
use App\Models\DossierReference;
use App\Models\User;
use App\Services\EffaceurContenuSupprime;
use App\Support\ValeursAudit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * Dossier d'affaire complet : champs saisis sur le web, article annoté,
 * les quatre annexes, et un journal d'audit qui recopie tout cela.
 */
function dossierAvecTout(User $user): Dossier
{
    $dossier = Dossier::factory()->for($user)->create([
        'name' => 'Affaire Moukoko c/ Banque du Pool',
        'description' => 'Licenciement contesté',
        'client_updated_at' => 5000,
    ]);
    $dossier->update([
        'internal_reference' => 'REF-2026-014',
        'client_name' => 'Jean Moukoko',
        'client_role' => 'demandeur',
        'adverse_party' => 'Banque du Pool',
        'jurisdiction' => 'Tribunal du travail de Brazzaville',
        'nature' => 'Licenciement',
    ]);
    $dossier->articles()->attach(Article::factory()->create()->id, ['personal_note' => 'Préavis non respecté', 'added_at' => 1]);
    DossierEcheance::factory()->for($dossier)->create(['title' => 'Audience de conciliation']);
    DossierReference::factory()->for($dossier)->create();
    DossierPiece::factory()->for($dossier)->create();
    DossierGeneratedDocument::factory()->for($dossier)->create();

    return $dossier;
}

/**
 * Lignes d'audit du dossier qui recopient encore au moins une valeur.
 */
function auditsDossierAvecValeurs(string $dossierId): int
{
    return DB::table('audits')
        ->where('auditable_type', (new Dossier)->getMorphClass())
        ->where('auditable_id', $dossierId)
        ->whereRaw('('.ValeursAudit::subsistent('old_values').' or '.ValeursAudit::subsistent('new_values').')')
        ->count();
}

/**
 * Le tombstone garde de quoi propager la suppression, et rien d'autre.
 */
function expectTombstoneVide(string $dossierId, string $userId, int $clientUpdatedAt): void
{
    $ligne = DB::table('dossiers')->where('id', $dossierId)->first();

    expect($ligne->deleted_at)->not->toBeNull()
        ->and($ligne->user_id)->toBe($userId)
        ->and((int) $ligne->client_updated_at)->toBe($clientUpdatedAt);

    foreach (EffaceurContenuSupprime::DOSSIER_EFFACE as $colonne => $valeur) {
        expect($ligne->{$colonne})->toBe($valeur);
    }

    foreach (EffaceurContenuSupprime::ANNEXES as $annexe) {
        expect(DB::table($annexe)->where('dossier_id', $dossierId)->count())->toBe(0);
    }

    expect(auditsDossierAvecValeurs($dossierId))->toBe(0);
}

it('empties the tombstone of a dossier deleted by the mobile sync', function () {
    $user = User::factory()->create();
    $supprime = dossierAvecTout($user);
    $garde = dossierAvecTout($user);
    Sanctum::actingAs($user);

    postJson('/api/v1/dossiers/sync', ['deleted_ids' => [$supprime->id]])
        ->assertOk()
        ->assertJsonPath('data.deleted_ids', [$supprime->id]);

    expectTombstoneVide($supprime->id, $user->id, 5000);

    // Le dossier voisin n'a rien perdu.
    expect($garde->refresh()->client_name)->toBe('Jean Moukoko')
        ->and($garde->articles()->first()->pivot->personal_note)->toBe('Préavis non respecté')
        ->and($garde->echeances()->count())->toBe(1)
        ->and(auditsDossierAvecValeurs($garde->id))->toBeGreaterThan(0);
});

it('still propagates the deletion, and a later edit brings back only what the device holds', function () {
    $user = User::factory()->create();
    $dossier = dossierAvecTout($user);
    Sanctum::actingAs($user);

    postJson('/api/v1/dossiers/sync', ['deleted_ids' => [$dossier->id]])->assertOk();

    // Un appareil resté hors ligne repousse sa version d'avant la suppression :
    // le tombstone l'emporte, et l'appareil reçoit la suppression.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [['id' => $dossier->id, 'name' => 'Affaire Moukoko c/ Banque du Pool', 'updated_at' => 5000]],
    ])
        ->assertJsonCount(0, 'data.dossiers')
        ->assertJsonPath('data.deleted_ids', [$dossier->id]);

    // Une modification postérieure à la suppression fait revenir le dossier
    // avec ce que l'appareil détient, sans rien de ce que le serveur a effacé.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [['id' => $dossier->id, 'name' => 'Repris hors ligne', 'updated_at' => 9000]],
    ])->assertJsonPath('data.dossiers.0.name', 'Repris hors ligne');

    $revenu = $dossier->refresh();
    expect($revenu->trashed())->toBeFalse()
        ->and($revenu->client_name)->toBeNull()
        ->and($revenu->adverse_party)->toBeNull()
        ->and($revenu->echeances()->count())->toBe(0);
});

it('empties the tombstone of a dossier deleted from the web, audit trail included', function () {
    $user = User::factory()->create();
    $dossier = dossierAvecTout($user);
    Sanctum::actingAs($user);

    deleteJson("/api/v1/dossiers/{$dossier->id}")->assertOk();

    expectTombstoneVide($dossier->id, $user->id, 5000);

    // La trace reste : l'événement et la date, plus les valeurs.
    $suppression = DB::table('audits')
        ->where('auditable_type', $dossier->getMorphClass())
        ->where('auditable_id', $dossier->id)
        ->where('event', 'deleted')
        ->first();

    expect($suppression)->not->toBeNull()
        ->and(json_decode($suppression->old_values, true))->toHaveKey('client_name', null);
});

it('empties an echeance deleted on its own and leaves its siblings alone', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    $supprimee = DossierEcheance::factory()->for($dossier)->create([
        'title' => 'Audience de plaidoirie',
        'note' => 'Apporter les bulletins de paie',
        'client_updated_at' => 7000,
    ]);
    $gardee = DossierEcheance::factory()->for($dossier)->create(['title' => 'Délai d\'appel']);
    Sanctum::actingAs($user);

    deleteJson("/api/v1/echeances/{$supprimee->id}")->assertOk();

    $ligne = DB::table('dossier_echeances')->where('id', $supprimee->id)->first();
    expect($ligne->deleted_at)->not->toBeNull()
        ->and($ligne->dossier_id)->toBe($dossier->id)
        ->and((int) $ligne->client_updated_at)->toBe(7000);

    foreach (EffaceurContenuSupprime::ECHEANCE_EFFACE as $colonne => $valeur) {
        expect($ligne->{$colonne})->toBe($valeur);
    }

    expect($gardee->refresh()->title)->toBe('Délai d\'appel');
});

it('declares the fate of every dossier and echeance column', function () {
    // Une colonne ajoutée doit dire si elle survit à la suppression (horodatage,
    // identifiant) ou si elle part avec le contenu (ce que l'usager saisit).
    $dossiers = [...EffaceurContenuSupprime::DOSSIER_CONSERVE, ...array_keys(EffaceurContenuSupprime::DOSSIER_EFFACE)];
    $echeances = [...EffaceurContenuSupprime::ECHEANCE_CONSERVE, ...array_keys(EffaceurContenuSupprime::ECHEANCE_EFFACE)];

    expect(collect(Schema::getColumnListing('dossiers'))->sort()->values()->all())
        ->toBe(collect($dossiers)->sort()->values()->all())
        ->and(collect(Schema::getColumnListing('dossier_echeances'))->sort()->values()->all())
        ->toBe(collect($echeances)->sort()->values()->all());
});

it('declares every table attached to a dossier', function () {
    // Une nouvelle annexe doit partir avec le contenu du dossier supprimé.
    $attachees = collect(DB::select(<<<'SQL'
        select c.conrelid::regclass::text as enfant
        from pg_constraint c
        where c.contype = 'f' and c.confrelid = 'dossiers'::regclass
        SQL))->pluck('enfant')->sort()->values()->all();

    expect($attachees)->toBe(collect(EffaceurContenuSupprime::ANNEXES)->sort()->values()->all());
});
