<?php

use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\LegalWatchSubscription;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\LegalWatchSubscriptionNotifier;
use Spatie\Permission\Models\Role;

/**
 * Déclencheur 1 de mibeko-dashboard#125 : une `DocumentRelation`
 * ABROGE/MODIFIE/COMPLETE confirmée alerte les abonnés du texte CIBLE
 * (celui qui « reçoit » la relation) — jamais les abonnés de l'acte source,
 * jamais pour une relation candidate ou rejetée, jamais si l'un des deux
 * textes n'est pas publié. Distinct de `LegalWatchNotificationTest.php`
 * (veille générale broadcast, non ciblée), qui reste intact.
 */
function editeurAbonnements(): User
{
    Role::findOrCreate('editor');

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

function abonneA(User $user, LegalDocument $document): LegalWatchSubscription
{
    return LegalWatchSubscription::create([
        'user_id' => $user->id,
        'watchable_type' => LegalWatchSubscription::WATCHABLE_DOCUMENT,
        'watchable_id' => $document->id,
    ]);
}

it('alerte l\'abonné du texte cible quand une relation ABROGE est créée déjà confirmée', function () {
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published', 'titre_officiel' => 'Loi n°2024-01']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published', 'titre_officiel' => 'Loi n°1990-05']);
    abonneA($subscriber, $target);

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ])->assertCreated();

    $relation = DocumentRelation::sole();
    $notification = Notification::where('user_id', $subscriber->id)->sole();

    expect($notification->type)->toBe(LegalWatchSubscriptionNotifier::TYPE_RELATION)
        ->and($notification->title)->toBe('Loi n°1990-05')
        ->and($notification->message)->toContain('abrogé')
        ->and($notification->message)->toContain('Loi n°2024-01')
        ->and($notification->dedupe_key)->toBe(LegalWatchSubscriptionNotifier::TYPE_RELATION.':'.$relation->id)
        ->and($notification->data['document_id'])->toBe($target->id)
        ->and($notification->data['relation_type'])->toBe('ABROGE');
});

it('alerte l\'abonné quand une relation candidate est validée', function () {
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($subscriber, $target);

    $relation = DocumentRelation::factory()->candidate()->create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'MODIFIE',
    ]);

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(0);

    $this->actingAs($editor)->postJson("/api/v1/relations/{$relation->id}/valider")->assertOk();

    $notification = Notification::where('user_id', $subscriber->id)->sole();

    expect($notification->message)->toContain('modifié')
        ->and($notification->dedupe_key)->toBe(LegalWatchSubscriptionNotifier::TYPE_RELATION.':'.$relation->id);
});

it('n\'alerte personne quand une relation candidate est rejetée', function () {
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($subscriber, $target);

    $relation = DocumentRelation::factory()->candidate()->create([
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ]);

    $this->actingAs($editor)->postJson("/api/v1/relations/{$relation->id}/rejeter")->assertOk();

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(0);
});

it('n\'alerte pas pour un type de relation hors périmètre (CITE)', function () {
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($subscriber, $target);

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'CITE',
    ])->assertCreated();

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(0);
});

it('n\'alerte pas quand le texte cible n\'est pas publié', function () {
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'review']);
    abonneA($subscriber, $target);

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ])->assertCreated();

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(0);
});

it('n\'alerte pas quand l\'acte source n\'est pas publié', function () {
    // Le texte cible reste publié, mais exposer le titre d'un acte source
    // encore en brouillon révélerait son existence à l'abonné.
    $editor = editeurAbonnements();
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($subscriber, $target);

    $relation = DocumentRelation::factory()->candidate()->create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'COMPLETE',
    ]);

    $this->actingAs($editor)->postJson("/api/v1/relations/{$relation->id}/valider")->assertOk();

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(0);
});

it('n\'alerte pas un abonné qui a coupé les alertes légales en in-app', function () {
    $editor = editeurAbonnements();
    $optedOut = User::factory()->create();
    $preferences = UserSetting::defaultNotificationPreferences();
    $preferences[UserSetting::TYPE_LEGAL_ALERT]['in_app'] = false;
    $optedOut->settingsOrCreate()->update(['notification_preferences' => $preferences]);

    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($optedOut, $target);

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ])->assertCreated();

    expect(Notification::where('user_id', $optedOut->id)->count())->toBe(0);
});

it('n\'alerte jamais un abonné du texte source, seulement ceux du texte cible', function () {
    $editor = editeurAbonnements();
    $sourceSubscriber = User::factory()->create();
    $targetSubscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($sourceSubscriber, $source);
    abonneA($targetSubscriber, $target);

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ])->assertCreated();

    expect(Notification::where('user_id', $sourceSubscriber->id)->count())->toBe(0)
        ->and(Notification::where('user_id', $targetSubscriber->id)->count())->toBe(1);
});

it('ne duplique pas l\'alerte si la même relation confirmée est rejouée', function () {
    $subscriber = User::factory()->create();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);
    abonneA($subscriber, $target);

    $relation = DocumentRelation::factory()->confirmed()->create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ]);

    $notifier = app(LegalWatchSubscriptionNotifier::class);
    $notifier->relationConfirmed($relation);
    $notifier->relationConfirmed($relation);

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(1);
});
