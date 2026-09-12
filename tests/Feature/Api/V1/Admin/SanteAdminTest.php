<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\Device;
use App\Models\ExtractionRun;
use App\Models\LegalDocument;
use App\Models\LegalWatchDispatch;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\UserInvitationNotification;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');
});

/**
 * `curation_status_changed_at` n'est pas `fillable` (posé par un hook
 * `saving`, jamais à la création) : il faut le forcer pour antidater un
 * document, même logique que `creerCompteLe` dans `OverviewAdminTest`.
 */
function creerDocumentDepuis(string $statut, Carbon $changeLe): LegalDocument
{
    $document = LegalDocument::factory()->create(['curation_status' => $statut]);
    $document->forceFill(['curation_status_changed_at' => $changeLe])->save();

    return $document->fresh();
}

// Préfixées `sante` : ces deux helpers dupliquent volontairement ceux de
// `SurveillerFileMailTest` — Pest charge tous les fichiers de test dans le
// même espace de noms global, un nom identique casserait la suite entière.
function santeInsererJobEchoue(string $classe, ?string $failedAt = null): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $classe, 'data' => ['commandName' => $classe]]),
        'exception' => 'Swift_TransportException: Connection refused',
        'failed_at' => $failedAt ?? now(),
    ]);
}

function santeInsererJobEnAttente(string $classe, int $ilYA): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $classe, 'data' => ['commandName' => $classe]]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes($ilYA)->timestamp,
        'created_at' => now()->subMinutes($ilYA)->timestamp,
    ]);
}

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse la console de santé sans authentification', function () {
    $this->getJson('/api/v1/admin/sante')->assertUnauthorized();
});

it('refuse la console de santé à un utilisateur non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/sante')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Forme de la charge
// ---------------------------------------------------------------------------

it('expose les extractions, la veille, la file de mail, le parc mobile et le corpus', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'extractions' => ['total_echecs', 'echecs'],
                'veille' => ['dispatches_delivres', 'dispatches_en_echec', 'appareils_joignables'],
                'mail' => ['echecs', 'bloques', 'total_echecs', 'total_bloques'],
                'parc_mobile' => ['total_actifs', 'versions', 'version_inconnue'],
                'corpus' => [
                    'published', 'pending', 'signales', 'versions_without_embedding',
                    'retard_publication' => ['seuil_jours', 'documents'],
                ],
            ],
        ]);
});

// ---------------------------------------------------------------------------
// Extractions en échec
// ---------------------------------------------------------------------------

it('donne le motif et le document d\'une extraction en échec, pas seulement un total', function () {
    $document = LegalDocument::factory()->create();

    ExtractionRun::create([
        'document_id' => $document->id,
        'source' => 'PARSING',
        'status' => 'failed',
        'finished_at' => now(),
        'meta' => ['error' => 'Timeout MinerU au bout de 120s'],
    ]);
    // Ne doit pas compter : extraction réussie.
    ExtractionRun::create([
        'document_id' => $document->id,
        'source' => 'PARSING',
        'status' => 'succeeded',
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.extractions.total_echecs', 1)
        ->assertJsonPath('data.extractions.echecs.0.motif', 'Timeout MinerU au bout de 120s')
        ->assertJsonPath('data.extractions.echecs.0.document_id', $document->id);

    expect($response->json('data.extractions.echecs.0.document_titre'))->toBe($document->titre_officiel);
});

it('tronque un motif trop long au lieu de renvoyer un dump SQL brut', function () {
    $document = LegalDocument::factory()->create();

    ExtractionRun::create([
        'document_id' => $document->id,
        'source' => 'PARSING',
        'status' => 'failed',
        'finished_at' => now(),
        'meta' => ['error' => str_repeat('x', 5000)],
    ]);

    $motif = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->json('data.extractions.echecs.0.motif');

    expect(mb_strlen($motif))->toBeLessThan(300);
});

// ---------------------------------------------------------------------------
// Veille et push
// ---------------------------------------------------------------------------

it('sépare les dispatches en échec des dispatches délivrés et compte les appareils réellement joignables', function () {
    $document = LegalDocument::factory()->create();

    LegalWatchDispatch::create([
        'document_ids' => [$document->id], 'document_count' => 1,
        'status' => LegalWatchDispatch::STATUS_DELIVERED,
    ]);
    LegalWatchDispatch::create([
        'document_ids' => [$document->id], 'document_count' => 1,
        'status' => LegalWatchDispatch::STATUS_FAILED, 'last_error' => 'FCM injoignable',
    ]);
    // En attente, pas encore en échec : ne doit peser sur aucun des deux compteurs.
    LegalWatchDispatch::create([
        'document_ids' => [$document->id], 'document_count' => 1,
        'status' => LegalWatchDispatch::STATUS_PENDING,
    ]);

    Device::factory()->create();
    Device::factory()->inactive()->create();
    Device::factory()->simulated()->create();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.veille.dispatches_delivres', 1)
        ->assertJsonPath('data.veille.dispatches_en_echec', 1)
        ->assertJsonPath('data.veille.appareils_joignables', 1);
});

// ---------------------------------------------------------------------------
// File de mail
// ---------------------------------------------------------------------------

it('remonte les échecs et blocages de la file de mail via la même mesure que la commande de surveillance', function () {
    santeInsererJobEchoue(PasswordResetCodeNotification::class);
    santeInsererJobEnAttente(UserInvitationNotification::class, 15);
    // Sous le seuil de blocage : pas encore un incident.
    santeInsererJobEnAttente(UserInvitationNotification::class, 3);
    // Sans rapport avec l'accès au compte : hors périmètre.
    santeInsererJobEchoue('App\\Jobs\\EmbedArticleChunkJob');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.mail.total_echecs', 1)
        ->assertJsonPath('data.mail.echecs.0.classe', 'PasswordResetCodeNotification')
        ->assertJsonPath('data.mail.total_bloques', 1)
        ->assertJsonPath('data.mail.bloques.0.classe', 'UserInvitationNotification');
});

// ---------------------------------------------------------------------------
// Parc mobile
// ---------------------------------------------------------------------------

it('ventile le parc mobile actif par version d\'app et compte les versions inconnues', function () {
    Device::factory()->count(2)->appVersion('1.2.0')->create();
    Device::factory()->appVersion('1.1.0')->create();
    Device::factory()->create(); // app_version = null (format hérité)
    // Inactif : ne doit apparaître nulle part.
    Device::factory()->inactive()->appVersion('1.2.0')->create();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.parc_mobile.total_actifs', 4)
        ->assertJsonPath('data.parc_mobile.version_inconnue', 1)
        ->assertJsonPath('data.parc_mobile.versions.0.version', '1.2.0')
        ->assertJsonPath('data.parc_mobile.versions.0.total', 2);
});

// ---------------------------------------------------------------------------
// Corpus
// ---------------------------------------------------------------------------

it('compte les documents signalés une seule fois même avec plusieurs signalements ouverts', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);

    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);
    // Résolu : ne doit pas compter le document comme signalé.
    $documentResolu = LegalDocument::factory()->create(['curation_status' => 'review']);
    CurationFlag::create([
        'document_id' => $documentResolu->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.corpus.signales', 1);
});

it('chiffre le retard de publication au lieu de le constater', function () {
    creerDocumentDepuis('draft', now()->subDays(10));
    // Sous le seuil : pas encore en retard.
    creerDocumentDepuis('review', now()->subDays(2));

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.corpus.retard_publication.seuil_jours', 7)
        ->assertJsonPath('data.corpus.retard_publication.documents', 1);
});

it('compte les versions d\'article sans embedding', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Avec embedding.',
        'validity_period' => '[2020-01-01,2021-01-01)',
        'embedding' => array_fill(0, 1024, 0.1),
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Sans embedding.',
        'validity_period' => '[2021-01-01,)',
        'embedding' => null,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/sante')
        ->assertOk()
        ->assertJsonPath('data.corpus.versions_without_embedding', 1);
});
