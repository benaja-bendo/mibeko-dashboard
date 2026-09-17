<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\LegalWatchSubscription;
use App\Models\Notification;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\LegalWatchSubscriptionNotifier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Déclencheur 2 de mibeko-dashboard#125 : un texte fraîchement PUBLIÉ, tagué
 * d'un thème suivi, alerte les abonnés de ce thème. Le hook réutilise la
 * réservation `watch_notified_at` de `LegalWatchNotifier::documentsPublished()`
 * (cf. `app/Services/LegalWatchNotifier.php`) : un texte republié n'est donc
 * jamais rejoué ici, sans mécanisme de déduplication supplémentaire.
 */
function editeurThemes(): User
{
    Permission::findOrCreate('documents.update');
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('documents.update');

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

/** Document prêt à publier, avec au moins un article (garde-fou de publication). */
function documentPubliable(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

function abonneATheme(User $user, Tag $tag): LegalWatchSubscription
{
    return LegalWatchSubscription::create([
        'user_id' => $user->id,
        'watchable_type' => LegalWatchSubscription::WATCHABLE_THEME,
        'watchable_id' => $tag->id,
    ]);
}

it('alerte l\'abonné d\'un thème quand un texte tagué est publié', function () {
    $editor = editeurThemes();
    $subscriber = User::factory()->create();
    $theme = Tag::create(['name' => 'Droit du travail', 'slug' => 'travail']);
    abonneATheme($subscriber, $theme);

    $document = documentPubliable(['titre_officiel' => 'Loi portant code du travail']);

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$document->id}", [
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'themes' => [$theme->id],
    ])->assertOk();

    $notification = Notification::where('user_id', $subscriber->id)
        ->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)
        ->sole();

    expect($notification->title)->toContain('Droit du travail')
        ->and($notification->message)->toBe('Loi portant code du travail')
        ->and($notification->dedupe_key)->toBe(LegalWatchSubscriptionNotifier::TYPE_THEME.':'.$theme->id.':'.$document->id)
        ->and($notification->data['tag_id'])->toBe($theme->id)
        ->and($notification->data['document_id'])->toBe($document->id);
});

it('n\'alerte pas l\'abonné d\'un autre thème que celui du texte publié', function () {
    $editor = editeurThemes();
    $subscriber = User::factory()->create();
    $suivi = Tag::create(['name' => 'Droit du travail', 'slug' => 'travail']);
    $autre = Tag::create(['name' => 'Fiscalité', 'slug' => 'fiscalite']);
    abonneATheme($subscriber, $suivi);

    $document = documentPubliable();

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$document->id}", [
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'themes' => [$autre->id],
    ])->assertOk();

    // Filtré par type : le lecteur reçoit quand même la veille générale
    // (broadcast à tout utilisateur, cf. LegalWatchNotificationTest) — ce que
    // ce ticket ne doit pas casser, seule l'alerte CIBLÉE de thème est en jeu.
    expect(Notification::where('user_id', $subscriber->id)->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(0);
});

it('n\'alerte personne pour un texte publié sans thème', function () {
    $editor = editeurThemes();
    $document = documentPubliable();

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$document->id}", [
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ])->assertOk();

    expect(Notification::where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(0);
});

it('alerte chaque abonné concerné lors d\'une publication de masse touchant plusieurs thèmes', function () {
    $editor = editeurThemes();
    $abonneTravail = User::factory()->create();
    $abonneFiscal = User::factory()->create();
    $travail = Tag::create(['name' => 'Droit du travail', 'slug' => 'travail']);
    $fiscalite = Tag::create(['name' => 'Fiscalité', 'slug' => 'fiscalite']);
    abonneATheme($abonneTravail, $travail);
    abonneATheme($abonneFiscal, $fiscalite);

    $docTravail = documentPubliable();
    $docFiscal = documentPubliable();
    $docTravail->tags()->sync([$travail->id]);
    $docFiscal->tags()->sync([$fiscalite->id]);

    $this->actingAs($editor)->patchJson('/api/v1/legal-documents/bulk', [
        'ids' => [$docTravail->id, $docFiscal->id],
        'action' => 'set_curation_status',
        'value' => LegalDocument::STATUS_PUBLISHED,
    ])->assertOk();

    expect(Notification::where('user_id', $abonneTravail->id)->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(1)
        ->and(Notification::where('user_id', $abonneFiscal->id)->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(1);
});

it('ne redouble pas l\'alerte de thème quand le texte est republié', function () {
    $editor = editeurThemes();
    $subscriber = User::factory()->create();
    $theme = Tag::create(['name' => 'Droit du travail', 'slug' => 'travail']);
    abonneATheme($subscriber, $theme);

    $document = documentPubliable();

    $publish = fn () => $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$document->id}", [
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'themes' => [$theme->id],
    ])->assertOk();

    $publish();
    // Quota générique `api` = 2 requêtes/minute en test : une seule
    // republication suffit à prouver l'idempotence.
    $publish();

    expect(Notification::where('user_id', $subscriber->id)->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(1);
});

it('n\'alerte pas un abonné qui a coupé les alertes légales en in-app', function () {
    $editor = editeurThemes();
    $optedOut = User::factory()->create();
    $preferences = UserSetting::defaultNotificationPreferences();
    $preferences[UserSetting::TYPE_LEGAL_ALERT]['in_app'] = false;
    $optedOut->settingsOrCreate()->update(['notification_preferences' => $preferences]);

    $theme = Tag::create(['name' => 'Droit du travail', 'slug' => 'travail']);
    abonneATheme($optedOut, $theme);

    $document = documentPubliable();

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$document->id}", [
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'themes' => [$theme->id],
    ])->assertOk();

    // Idem : `legal_alert` coupé ne coupe pas `new_document` (veille générale,
    // toujours active par défaut) — seule l'alerte de thème doit disparaître.
    expect(Notification::where('user_id', $optedOut->id)->where('type', LegalWatchSubscriptionNotifier::TYPE_THEME)->count())->toBe(0);
});
