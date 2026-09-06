<?php

use App\Models\AiUsageLog;
use App\Models\ContactMessage;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\PlanGrant;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 * `created_at` n'est pas `fillable` sur `AiUsageLog` (journal en ajout seul,
 * `UPDATED_AT = null`) : il faut le forcer pour antidater une ligne.
 */
function journaliserAppelIa(array $attributs = [], ?Carbon $quand = null): AiUsageLog
{
    $log = new AiUsageLog;
    $log->forceFill(array_merge(
        ['route' => 'assistant/chat', 'status' => 'success'],
        $attributs,
        ['created_at' => $quand ?? now()],
    ));
    $log->save();

    return $log;
}

function creerCompteLe(Carbon $quand): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->forceFill(['created_at' => $quand])->save());
}

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse la vue d\'ensemble admin sans authentification', function () {
    $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
});

it('refuse la vue d\'ensemble admin à un utilisateur non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/overview')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Forme de la charge
// ---------------------------------------------------------------------------

it('expose l\'inventaire, ce qui demande une action, les tendances, l\'adoption et le corpus', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'content' => ['documents', 'articles', 'official_journals'],
                'referentiels' => ['document_types', 'institutions', 'tags'],
                'people' => ['users'],
                'attention' => [
                    'open_flags',
                    'open_flags_blocking',
                    'open_flags_warning',
                    'failed_extractions',
                    'ai_errors_24h',
                    'unhandled_contacts',
                    'plan_grants_expiring_soon',
                ],
                'trend_window_days',
                'trends' => [
                    'new_users' => ['value', 'previous'],
                    'ai_questions' => ['value', 'previous'],
                    'ai_cost_fcfa' => ['value', 'previous'],
                ],
                'adoption' => ['mobile_active', 'web_active', 'total_active', 'window_days'],
                'corpus' => ['published', 'pending', 'versions_without_embedding'],
            ],
        ]);
});

// ---------------------------------------------------------------------------
// Ce qui demande une action
// ---------------------------------------------------------------------------

it('ne compte que les erreurs IA des dernières 24 heures', function () {
    journaliserAppelIa(['status' => 'error'], now()->subHours(2));
    journaliserAppelIa(['status' => 'error'], now()->subHours(23));
    // Hors fenêtre : la panne d'avant-hier n'est plus une action à mener.
    journaliserAppelIa(['status' => 'error'], now()->subDays(3));
    // Un succès récent n'est pas une erreur.
    journaliserAppelIa([], now()->subHour());

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.attention.ai_errors_24h', 2);
});

it('ne compte que les messages de contact non traités', function () {
    ContactMessage::create(['name' => 'Awa', 'email' => 'awa@example.cg', 'message' => 'Bonjour', 'handled' => false]);
    ContactMessage::create(['name' => 'Blaise', 'email' => 'blaise@example.cg', 'message' => 'Question', 'handled' => false]);
    ContactMessage::create(['name' => 'Chris', 'email' => 'chris@example.cg', 'message' => 'Réglé', 'handled' => true]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.attention.unhandled_contacts', 2);
});

it('ne remonte que les abonnements Pro qui expirent dans les sept jours', function () {
    PlanGrant::factory()->create([
        'user_id' => $this->proUser->id,
        'ends_at' => now()->addDays(3),
    ]);
    // Trop loin : rien à préparer aujourd'hui.
    PlanGrant::factory()->create([
        'user_id' => User::factory()->create()->id,
        'ends_at' => now()->addDays(30),
    ]);
    // Déjà expiré : ce n'est plus une échéance, c'est un abonné perdu.
    PlanGrant::factory()->create([
        'user_id' => User::factory()->create()->id,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.attention.plan_grants_expiring_soon', 1);
});

it('ventile les signalements ouverts par sévérité', function () {
    $document = LegalDocument::factory()->create();

    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);
    // Résolu : sorti de tous les compteurs, quelle que soit sa sévérité.
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.attention.open_flags', 2)
        ->assertJsonPath('data.attention.open_flags_blocking', 1)
        ->assertJsonPath('data.attention.open_flags_warning', 1);
});

// ---------------------------------------------------------------------------
// Tendances
// ---------------------------------------------------------------------------

it('compare chaque tendance à la fenêtre précédente de même longueur', function () {
    // Deux comptes cette semaine, un la semaine d'avant. Les deux comptes du
    // `beforeEach` sont créés maintenant : ils tombent dans la fenêtre courante.
    creerCompteLe(now()->subDays(2));
    creerCompteLe(now()->subDays(9));
    // Hors des deux fenêtres : ne doit peser sur aucun des deux chiffres.
    creerCompteLe(now()->subDays(40));

    journaliserAppelIa(['cost_estimated_fcfa' => 40], now()->subDays(1));
    journaliserAppelIa(['cost_estimated_fcfa' => 60], now()->subDays(3));
    journaliserAppelIa(['cost_estimated_fcfa' => 25], now()->subDays(10));
    // Un échec n'est pas une question rendue : ni compté, ni facturé.
    journaliserAppelIa(['status' => 'error', 'cost_estimated_fcfa' => 999], now()->subDays(2));

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk();

    // 2 comptes antidatés + les 2 comptes du beforeEach, créés à l'instant.
    $response->assertJsonPath('data.trends.new_users.value', 3)
        ->assertJsonPath('data.trends.new_users.previous', 1)
        ->assertJsonPath('data.trends.ai_questions.value', 2)
        ->assertJsonPath('data.trends.ai_questions.previous', 1)
        ->assertJsonPath('data.trend_window_days', 7);

    expect((float) $response->json('data.trends.ai_cost_fcfa.value'))->toBe(100.0)
        ->and((float) $response->json('data.trends.ai_cost_fcfa.previous'))->toBe(25.0);
});

// ---------------------------------------------------------------------------
// Adoption et corpus
// ---------------------------------------------------------------------------

it('sépare les comptes actifs par surface sans additionner deux fois un compte mixte', function () {
    $mixte = User::factory()->create();
    $mixte->createToken('Mobile Device')->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();
    $mixte->createToken('mibeko-saas-web')->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();

    $mobileSeul = User::factory()->create();
    $mobileSeul->createToken('Mobile Device')->accessToken->forceFill(['last_used_at' => now()->subDays(2)])->save();

    // Jeton dormant : hors de la fenêtre d'activité de 30 jours.
    $dormant = User::factory()->create();
    $dormant->createToken('Mobile Device')->accessToken->forceFill(['last_used_at' => now()->subDays(45)])->save();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.adoption.mobile_active', 2)
        ->assertJsonPath('data.adoption.web_active', 1)
        // 2 comptes distincts, pas 3 : le compte mixte n'est pas compté deux fois.
        ->assertJsonPath('data.adoption.total_active', 2)
        ->assertJsonPath('data.adoption.window_days', 30);
});

it('dérive le nombre de documents en attente du total plutôt que d\'une inégalité SQL', function () {
    LegalDocument::factory()->count(2)->create(['curation_status' => 'published']);
    LegalDocument::factory()->create(['curation_status' => 'draft']);
    LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.corpus.published', 2)
        ->assertJsonPath('data.corpus.pending', 2);
});
