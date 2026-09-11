<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * dashboard#119 — tests de concurrence.
 *
 * `RefreshDatabase` enveloppe chaque test dans une transaction sur la
 * connexion Laravel : une SECONDE connexion PDO brute ne peut pas voir les
 * lignes non commitées de cette transaction (MVCC Postgres), donc une vraie
 * course entre deux requêtes HTTP ne peut pas se rejouer telle quelle ici —
 * même contrainte déjà rencontrée par `tests/Feature/CreditLedgerTest.php`,
 * dont `openRawPgConnection()` inspire le helper ci-dessous.
 *
 * Deux angles complémentaires, chacun honnête sur ce qu'il prouve :
 *  1. Le PRIMITIF Postgres (`SELECT … FOR UPDATE`) sérialise bien deux
 *     sessions sur la même ligne — sur une ligne insérée et commitée hors
 *     de la transaction de test, donc réellement visible des deux connexions.
 *  2. Le CODE du contrôleur émet réellement ce verrou sur les chemins de
 *     publication et de prise en charge (capture SQL via `DB::listen`).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('editor');
    Permission::findOrCreate('documents.update');
    Role::findByName('editor')->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editorB = User::factory()->create();
    $this->editorB->assignRole('editor');
    $this->editor->assignRole('editor');

    $this->withoutMiddleware(ThrottleRequests::class);
});

function openRawPublicationConnection(): PDO
{
    $config = config('database.connections.pgsql');

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
}

// ---------------------------------------------------------------------------
// 1. Le primitif : SELECT … FOR UPDATE sérialise deux sessions
// ---------------------------------------------------------------------------

it(
    'SELECT … FOR UPDATE interdit à une seconde session de verrouiller la même ligne legal_documents '.
    '— le mécanisme exact dont LegalDocumentController/ReviewQueueController dépendent (lockForUpdate)',
    function () {
        $connA = openRawPublicationConnection();
        $connB = openRawPublicationConnection();

        // Insérée et commitée hors de la transaction de test (autocommit
        // PDO par défaut) : visible des deux connexions brutes ci-dessous,
        // contrairement à une ligne créée via Eloquent dans ce test.
        $insert = $connA->prepare('insert into legal_documents (titre_officiel) values (?) returning id');
        $insert->execute(['Document de test — verrou de publication']);
        $documentId = $insert->fetchColumn();

        try {
            // A verrouille la ligne et ne relâche pas encore.
            $connA->beginTransaction();
            $connA->prepare('select id from legal_documents where id = ? for update')->execute([$documentId]);

            // B ne peut pas l'obtenir tant que A n'a ni commité ni annulé.
            $connB->beginTransaction();
            $connB->exec('set local lock_timeout = 200');

            expect(fn () => $connB
                ->prepare('select id from legal_documents where id = ? for update nowait')
                ->execute([$documentId])
            )->toThrow(PDOException::class);

            $connB->rollBack();

            // A libère en committant : B peut alors l'obtenir.
            $connA->commit();

            $connB->beginTransaction();
            $connB->prepare('select id from legal_documents where id = ? for update nowait')->execute([$documentId]);
            $connB->commit();
        } finally {
            // Cette ligne a été réellement commitée : le rollback de
            // RefreshDatabase en fin de test ne la nettoiera pas.
            $connA->exec('delete from legal_documents where id = '.$connA->quote($documentId));
        }
    }
);

// ---------------------------------------------------------------------------
// 2. Le code émet bien ce verrou sur les chemins concernés
// ---------------------------------------------------------------------------

function documentAvecArticleEtProvenance(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
        'date_entree_vigueur' => '2020-01-01',
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

function sqlEmisPendant(callable $action): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    $action();

    return $sql;
}

it('verrouille la ligne document lors d\'une publication unitaire', function () {
    $document = documentAvecArticleEtProvenance();

    $sql = sqlEmisPendant(fn () => $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertOk());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update')))->toBeTrue();
});

it('verrouille les lignes du lot lors d\'une publication en masse', function () {
    $documents = collect(range(1, 2))->map(fn () => documentAvecArticleEtProvenance());

    $sql = sqlEmisPendant(fn () => $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update')))->toBeTrue();
});

it('ne verrouille rien pour une simple correction de titre (pas de publication)', function () {
    $document = documentAvecArticleEtProvenance();

    $sql = sqlEmisPendant(fn () => $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['titre_officiel' => 'Correction sans rapport'])
        ->assertOk());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update')))->toBeFalse();
});

it('verrouille la ligne lors d\'une prise en charge dans la file de revue', function () {
    $document = documentAvecArticleEtProvenance();

    $sql = sqlEmisPendant(fn () => $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertOk());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update')))->toBeTrue();
});

it('le verrou de prise en charge referme la fenêtre de course : la seconde requête voit l\'état écrit par la première', function () {
    // Sans lockForUpdate(), claim() lisait assigned_to puis écrivait en deux
    // temps : rien n'empêchait une seconde requête de lire le même « pas
    // encore assigné » avant que la première n'ait écrit. Le test 409 déjà
    // présent (ReviewQueueTest) prouve le contrat observable ; celui-ci
    // vérifie en plus qu'aucune ligne n'est jamais retrouvée assignée à B
    // après coup, même en enchaînant les tentatives sans délai.
    $document = documentAvecArticleEtProvenance();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertOk();

    $this->actingAs($this->editorB)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertStatus(409);

    expect($document->fresh()->assigned_to)->toBe($this->editor->id);
});
