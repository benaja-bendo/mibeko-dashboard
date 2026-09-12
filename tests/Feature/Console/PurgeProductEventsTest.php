<?php

use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ProductActivationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * mibeko-dashboard#137 : rétention bornée du détail + agrégat durable calculé
 * AVANT suppression, même doctrine que `PruneAudits` pour la partie purge.
 */
function evenementLe(array $attributes, string $createdAt): ProductActivationEvent
{
    $event = ProductActivationEvent::create($attributes);
    ProductActivationEvent::whereKey($event->id)->update(['created_at' => $createdAt]);

    return $event->fresh();
}

function utilisateurCreeLeDepuis(int $joursDepuis): User
{
    $user = User::factory()->create();
    User::whereKey($user->id)->update(['created_at' => now()->subDays($joursDepuis)]);

    return $user->fresh();
}

it('purge les événements plus vieux que N jours et conserve les plus récents', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $ancien = evenementLe([
        'user_id' => $user->id, 'event_type' => 'search_useful', 'surface' => 'web',
        'reference_type' => 'article', 'reference_id' => $article->id, 'client_event_id' => 'old',
    ], now()->subDays(200)->toDateTimeString());

    $recent = evenementLe([
        'user_id' => $user->id, 'event_type' => 'search_useful', 'surface' => 'web',
        'reference_type' => 'article', 'reference_id' => $article->id, 'client_event_id' => 'new',
    ], now()->subDays(10)->toDateTimeString());

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();

    expect(ProductActivationEvent::whereKey($ancien->id)->exists())->toBeFalse();
    expect(ProductActivationEvent::whereKey($recent->id)->exists())->toBeTrue();
});

it('teste la rétention à la frontière : J-179 survit, J-181 est supprimé', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $survit = evenementLe([
        'user_id' => $user->id, 'event_type' => 'search_useful', 'surface' => 'web',
        'reference_type' => 'article', 'reference_id' => $article->id, 'client_event_id' => 'survit',
    ], now()->subDays(179)->toDateTimeString());

    $supprime = evenementLe([
        'user_id' => $user->id, 'event_type' => 'search_useful', 'surface' => 'web',
        'reference_type' => 'article', 'reference_id' => $article->id, 'client_event_id' => 'supprime',
    ], now()->subDays(181)->toDateTimeString());

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();

    expect(ProductActivationEvent::whereKey($survit->id)->exists())->toBeTrue();
    expect(ProductActivationEvent::whereKey($supprime->id)->exists())->toBeFalse();
});

it('agrège durablement une cohorte mature avant de purger son détail', function () {
    // 120 jours : marge de sécurité au-delà de activation_window_days (90) +
    // la semaine entière (7j), quel que soit le jour de la semaine de création.
    $user = utilisateurCreeLeDepuis(120);
    $log = AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => true,
    ]);
    AiUsageLog::whereKey($log->id)->update(['created_at' => now()->subDays(119)]);

    evenementLe([
        'user_id' => $user->id, 'event_type' => 'source_opened_after_answer', 'surface' => 'web',
        'reference_type' => 'ai_usage_log', 'reference_id' => $log->id, 'client_event_id' => 'e1',
    ], now()->subDays(115)->toDateTimeString());

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();

    $ligne = DB::table('product_activation_cohort_stats')->first();
    expect($ligne)->not->toBeNull();
    expect($ligne->cohort_size)->toBe(1);
    expect($ligne->reached_success_reply)->toBe(1);
    expect($ligne->reached_activation_candidate)->toBe(1);
});

it('ne recalcule pas deux fois une cohorte déjà agrégée', function () {
    utilisateurCreeLeDepuis(120);

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();
    $premierComputedAt = DB::table('product_activation_cohort_stats')->value('computed_at');

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();

    expect(DB::table('product_activation_cohort_stats')->count())->toBe(1);
    expect(DB::table('product_activation_cohort_stats')->value('computed_at'))->toBe($premierComputedAt);
});

it('n\'agrège pas une cohorte pas encore mature', function () {
    utilisateurCreeLeDepuis(10);

    $this->artisan('mibeko:purge-product-events', ['--days' => 180])->assertSuccessful();

    expect(DB::table('product_activation_cohort_stats')->count())->toBe(0);
});
