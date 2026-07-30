<?php

use App\Jobs\SendLegalWatchNotifications;
use App\Models\Article;
use App\Models\Device;
use App\Models\LegalDocument;
use App\Models\LegalWatchDispatch;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserSetting;
use App\Observers\ArticleVersionObserver;
use App\Services\LegalWatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    // Jeton FCM injecté par la configuration : évite l'échange OAuth Google et
    // laisse Http::fake() intercepter l'appel réel à FCM.
    config([
        'services.firebase.project_id' => 'mibeko-test',
        'services.firebase.access_token' => 'fake-access-token',
    ]);
    Http::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

/**
 * Document prêt à être publié : hors catalogue publié, avec au moins un article
 * (garde-fou de publication) et jamais annoncé.
 */
function publishableDocument(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

function fcmPushCount(): int
{
    $sent = 0;

    Http::recorded(function ($request) use (&$sent) {
        if (str_contains($request->url(), 'fcm.googleapis.com')) {
            $sent++;
        }
    });

    return $sent;
}

/**
 * Charges `message` réellement envoyées à FCM, indexées par jeton destinataire.
 *
 * @return array<string, array<string, mixed>>
 */
function fcmMessagesByToken(): array
{
    $messages = [];

    Http::recorded(function ($request) use (&$messages) {
        if (str_contains($request->url(), 'fcm.googleapis.com')) {
            $message = $request['message'];
            $messages[$message['token']] = $message;
        }
    });

    return $messages;
}

/**
 * Efface le slug d'un document sans passer par Eloquent — c'est exactement
 * l'état laissé par l'ingestion Python (écriture directe en base).
 */
function stripSlug(LegalDocument $document): void
{
    DB::table('legal_documents')->where('id', $document->id)->update(['slug' => null]);
}

it('écrit une notification et pousse aux appareils à la publication unitaire', function () {
    $reader = User::factory()->create();
    $device = Device::factory()->create(); // appareil invité (user_id null)
    $document = publishableDocument(['titre_officiel' => 'Loi sur le travail']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $notifications = Notification::where('user_id', $reader->id)->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(LegalWatchNotifier::TYPE_DOCUMENT)
        ->and($notifications->first()->message)->toBe('Loi sur le travail');

    // Le payload data doit permettre au mobile d'ouvrir directement le texte.
    $data = $notifications->first()->data;
    expect($data['type'])->toBe(LegalWatchNotifier::TYPE_DOCUMENT)
        ->and($data['slug'])->toBe($document->fresh()->slug)
        ->and($data['url'])->toContain('/textes/'.$document->fresh()->slug);

    Http::assertSent(function ($request) use ($device) {
        return str_contains($request->url(), 'fcm.googleapis.com')
            && $request['message']['token'] === $device->push_token
            && $request['message']['data']['type'] === LegalWatchNotifier::TYPE_DOCUMENT;
    });

    expect($document->fresh()->watch_notified_at)->not->toBeNull();
});

it('ne produit aucune seconde notification quand le document est republié', function () {
    $reader = User::factory()->create();
    Device::factory()->create();
    $document = publishableDocument();

    $publish = fn () => $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $publish();
    $firstNotifiedAt = $document->fresh()->watch_notified_at;
    $pushesAfterFirst = fcmPushCount();

    // Le quota générique `api` vaut 2 requêtes/minute en test : une seule
    // republication suffit à prouver que le marqueur d'idempotence tient.
    $publish();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(1)
        ->and(fcmPushCount())->toBe($pushesAfterFirst)
        ->and($document->fresh()->watch_notified_at->toDateTimeString())
        ->toBe($firstNotifiedAt->toDateTimeString());
});

it('n\'envoie qu\'une synthèse quand la publication de masse dépasse le seuil', function () {
    config(['mobile.watch.digest_threshold' => 3]);

    $reader = User::factory()->create();
    Device::factory()->create();

    $documents = collect(range(1, 5))->map(fn () => publishableDocument());

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $notifications = Notification::where('user_id', $reader->id)->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe(LegalWatchNotifier::TYPE_DIGEST)
        ->and($notifications->first()->title)->toBe('5 nouveaux textes publiés')
        ->and($notifications->first()->data['count'])->toBe('5');

    // Un seul push (une synthèse), pas cinq.
    expect(fcmPushCount())->toBe(1);

    // Tous les documents sont marqués comme annoncés.
    expect(LegalDocument::whereIn('id', $documents->pluck('id'))->whereNull('watch_notified_at')->count())->toBe(0);
});

it('émet une alerte par texte quand la publication de masse reste sous le seuil', function () {
    config(['mobile.watch.digest_threshold' => 3]);

    $reader = User::factory()->create();
    $documents = collect(range(1, 2))->map(fn () => publishableDocument());

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $notifications = Notification::where('user_id', $reader->id)->get();

    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('type')->unique()->all())->toBe([LegalWatchNotifier::TYPE_DOCUMENT]);
});

it('ne notifie pas un utilisateur qui a coupé la veille juridique', function () {
    $optedOut = User::factory()->create();
    $preferences = UserSetting::defaultNotificationPreferences();
    $preferences['new_document'] = ['email' => false, 'push' => false, 'in_app' => false];
    $optedOut->settingsOrCreate()->update(['notification_preferences' => $preferences]);

    $device = Device::factory()->ownedBy($optedOut)->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    expect(Notification::where('user_id', $optedOut->id)->count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && $request['message']['token'] === $device->push_token);
});

it('pousse à l\'appareil d\'un utilisateur qui a activé le canal push', function () {
    $subscriber = User::factory()->create();
    $preferences = UserSetting::defaultNotificationPreferences();
    $preferences['new_document']['push'] = true;
    $subscriber->settingsOrCreate()->update(['notification_preferences' => $preferences]);

    $device = Device::factory()->ownedBy($subscriber)->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && $request['message']['token'] === $device->push_token);
});

it('ne pousse jamais vers un jeton simulé ni vers un appareil inactif', function () {
    $simulated = Device::factory()->simulated()->create();
    $inactive = Device::factory()->inactive()->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && in_array($request['message']['token'], [$simulated->push_token, $inactive->push_token], true));
});

it('n\'annonce rien lorsque la mise à jour ne publie pas', function () {
    $reader = User::factory()->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_VALIDATED,
        ])
        ->assertOk();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(0)
        ->and($document->fresh()->watch_notified_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Forme du message selon la version de l'app installée
|--------------------------------------------------------------------------
|
| Le data-only est la cible (seule forme qui préserve le deep link), mais l'app
| publiée sur les stores (v1.0/v1.1) ne sait afficher qu'un bloc `notification`.
| Le serveur adapte donc la forme à l'appareil destinataire plutôt que d'attendre
| l'adoption de la v1.2 : une alerte est consommée définitivement par
| `watch_notified_at`, une alerte invisible est une alerte perdue.
|
*/

it('envoie un push data-only : aucun bloc notification, titre et corps dans data', function () {
    $device = Device::factory()->appVersion('1.2.0')->create();
    $document = publishableDocument(['titre_officiel' => 'Loi de finances 2026']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Http::assertSent(function ($request) use ($device) {
        if (! str_contains($request->url(), 'fcm.googleapis.com')) {
            return false;
        }

        $message = $request['message'];

        return $message['token'] === $device->push_token
            // Un bloc `notification` (de premier niveau ou Android) ferait
            // afficher l'alerte par le système sans passer par
            // `onMessageReceived` : le deep link ne serait jamais construit.
            && ! array_key_exists('notification', $message)
            && ! array_key_exists('notification', $message['android'])
            // Data-only ⇒ priorité haute, sinon Android peut différer la remise.
            && $message['android']['priority'] === 'high'
            && $message['data']['title'] === 'Nouveau texte publié'
            && $message['data']['message'] === 'Loi de finances 2026'
            && $message['data']['deeplink'] !== ''
            // iOS n'est pas concerné par cette classification : l'alerte
            // affichable lui reste servie par le bloc APNs.
            && $message['apns']['payload']['aps']['alert']['title'] === 'Nouveau texte publié';
    });
});

it('sert le format hérité à un appareil dont l\'app est antérieure à la 1.2', function () {
    $device = Device::factory()->appVersion('1.0.0')->create();
    $document = publishableDocument(['titre_officiel' => 'Loi portant code du travail']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $message = fcmMessagesByToken()[$device->push_token] ?? null;

    expect($message)->not->toBeNull();

    // Bloc `notification` : sans lui, cette génération d'app n'affiche RIEN.
    expect($message['notification']['title'])->toBe('Nouveau texte publié')
        ->and($message['notification']['body'])->toBe('Loi portant code du travail')
        // Le `data` reste identique à la forme cible : la v1.2 en avant-plan
        // construit son deep link même si le serveur s'est trompé de génération.
        ->and($message['data']['title'])->toBe('Nouveau texte publié')
        ->and($message['data']['message'])->toBe('Loi portant code du travail')
        ->and($message['data']['slug'])->toBe($document->fresh()->slug)
        ->and($message['data']['deeplink'])->toBe('mibeko://textes/'.$document->fresh()->slug);
});

it('sert le format hérité à un appareil dont la version est inconnue', function () {
    // Tout le parc enregistré avant que l'app n'annonce sa version : `app_version`
    // est nul en base, et ces appareils sont majoritaires.
    $device = Device::factory()->create();

    expect($device->app_version)->toBeNull();

    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $message = fcmMessagesByToken()[$device->push_token] ?? null;

    expect($message)->not->toBeNull()
        ->and($message['notification']['title'])->toBe('Nouveau texte publié');
});

it('envoie deux formes différentes dans un parc mixte', function () {
    $modern = Device::factory()->appVersion('1.2.0')->create();
    $legacy = Device::factory()->appVersion('1.1.0')->create();
    $unknown = Device::factory()->create();

    $document = publishableDocument(['titre_officiel' => 'Loi sur les télécommunications']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $messages = fcmMessagesByToken();

    // Un envoi par appareil, aucun jeton servi deux fois malgré le double
    // découpage (par forme, puis par tranche).
    expect($messages)->toHaveCount(3)
        ->and(fcmPushCount())->toBe(3);

    expect($messages[$modern->push_token])->not->toHaveKey('notification')
        ->and($messages[$legacy->push_token]['notification']['body'])->toBe('Loi sur les télécommunications')
        ->and($messages[$unknown->push_token]['notification']['body'])->toBe('Loi sur les télécommunications');

    // Le contenu utile ne dépend pas de la forme.
    foreach ($messages as $message) {
        expect($message['data']['slug'])->toBe($document->fresh()->slug)
            ->and($message['android']['priority'])->toBe('high');
    }
});

it('découpe chaque forme en tranches sans mélanger les parcs', function () {
    // Tranches de 1 jeton : force plusieurs jobs d'envoi par forme, ce qui est
    // exactement la situation où un mauvais regroupement se verrait.
    config(['mobile.watch.push_chunk_size' => 1]);

    $modern = Device::factory()->count(2)->appVersion('1.2.0')->create();
    $old = Device::factory()->count(2)->appVersion('1.0.0')->create();

    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $messages = fcmMessagesByToken();

    expect($messages)->toHaveCount(4);

    foreach ($modern as $device) {
        expect($messages[$device->push_token])->not->toHaveKey('notification');
    }

    foreach ($old as $device) {
        expect($messages[$device->push_token])->toHaveKey('notification');
    }
});

/*
|--------------------------------------------------------------------------
| Slug garanti avant l'annonce
|--------------------------------------------------------------------------
*/

it('génère le slug manquant avant d\'annoncer une publication de masse', function () {
    $reader = User::factory()->create();
    Device::factory()->create();
    $document = publishableDocument(['titre_officiel' => 'Loi sur les hydrocarbures']);

    // Publication de masse = `UPDATE` de query builder : aucun mutateur Eloquent
    // ne tourne, le document arriverait donc sans slug à l'annonce.
    stripSlug($document);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$document->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $slug = (string) $document->fresh()->slug;

    expect($slug)->toContain('hydrocarbures');

    $notification = Notification::where('user_id', $reader->id)->sole();

    expect($notification->data['slug'])->toBe($slug)
        ->and($notification->data['deeplink'])->toBe('mibeko://textes/'.$slug);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && $request['message']['data']['slug'] === $slug);
});

it('ne consomme pas le marqueur d\'annonce quand le slug reste introuvable', function () {
    $reader = User::factory()->create();
    $document = publishableDocument();

    DB::table('legal_documents')->where('id', $document->id)->update([
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'slug' => null,
    ]);

    // Réparation de slug neutralisée : reproduit le cas résiduel d'un document
    // qui reste sans slug au moment de la réservation.
    $notifier = new class extends LegalWatchNotifier
    {
        protected function ensureAnnounceableSlugs(array $documentIds): void {}
    };

    // Ni alerte, ni marqueur consommé : le texte reste annonçable plus tard.
    expect($notifier->documentsPublished([$document->id]))->toBe(0)
        ->and($document->fresh()->watch_notified_at)->toBeNull()
        ->and(Notification::where('user_id', $reader->id)->count())->toBe(0)
        ->and(LegalWatchDispatch::count())->toBe(0)
        ->and(fcmPushCount())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Idempotence de la DIFFUSION et reprise
|--------------------------------------------------------------------------
*/

it('ouvre un journal de diffusion pour chaque lot réservé', function () {
    Device::factory()->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $dispatch = LegalWatchDispatch::sole();

    expect($dispatch->document_ids)->toBe([$document->id])
        ->and($dispatch->document_count)->toBe(1)
        ->and($dispatch->status)->toBe(LegalWatchDispatch::STATUS_DELIVERED)
        ->and($dispatch->in_app_written_at)->not->toBeNull()
        ->and($dispatch->pushes_dispatched_at)->not->toBeNull()
        ->and($dispatch->delivered_at)->not->toBeNull();
});

it('ne rediffuse rien quand la file rejoue un lot déjà diffusé', function () {
    $reader = User::factory()->create();
    Device::factory()->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $pushesAfterFirst = fcmPushCount();
    $dispatch = LegalWatchDispatch::sole();

    (new SendLegalWatchNotifications($dispatch->id))->handle();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(1)
        ->and(fcmPushCount())->toBe($pushesAfterFirst)
        ->and($dispatch->fresh()->attempts)->toBe(1);
});

it('ne redonne pas deux fois la même alerte quand le job reprend l\'étape in-app', function () {
    $reader = User::factory()->create();
    Device::factory()->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $pushesAfterFirst = fcmPushCount();
    $dispatch = LegalWatchDispatch::sole();

    // Échec survenu au milieu des tranches d'utilisateurs : la file rejoue le
    // job, qui refait l'étape in-app DEPUIS LE DÉBUT. Seule l'unicité
    // (user_id, dedupe_key) empêche alors le doublon.
    $dispatch->forceFill([
        'status' => LegalWatchDispatch::STATUS_FAILED,
        'in_app_written_at' => null,
        'delivered_at' => null,
    ])->save();

    (new SendLegalWatchNotifications($dispatch->id))->handle();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(1)
        ->and(fcmPushCount())->toBe($pushesAfterFirst)
        ->and($dispatch->fresh()->status)->toBe(LegalWatchDispatch::STATUS_DELIVERED);
});

it('marque le lot en échec quand le job abandonne définitivement', function () {
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    $dispatch = LegalWatchDispatch::sole();
    $dispatch->forceFill([
        'status' => LegalWatchDispatch::STATUS_PENDING,
        'delivered_at' => null,
    ])->save();

    (new SendLegalWatchNotifications($dispatch->id))->failed(new RuntimeException('FCM injoignable'));

    expect($dispatch->fresh()->status)->toBe(LegalWatchDispatch::STATUS_FAILED)
        ->and($dispatch->fresh()->last_error)->toContain('FCM injoignable');
});

it('rejoue une alerte réservée dont la diffusion n\'a jamais abouti', function () {
    $reader = User::factory()->create();
    Device::factory()->create();
    $document = publishableDocument();

    // État laissé par un worker tombé : le texte est publié ET marqué comme
    // annoncé, mais aucune alerte n'est jamais partie. Sans journal, il ne
    // serait plus jamais candidat.
    DB::table('legal_documents')->where('id', $document->id)->update([
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'watch_notified_at' => now(),
    ]);

    $dispatch = LegalWatchDispatch::create([
        'document_ids' => [$document->id],
        'document_count' => 1,
        'status' => LegalWatchDispatch::STATUS_FAILED,
        'last_error' => 'FCM injoignable',
    ]);

    expect(Notification::where('user_id', $reader->id)->count())->toBe(0);

    $this->artisan('mibeko:retry-legal-watch', ['--older-than' => 0])->assertSuccessful();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(1)
        ->and(fcmPushCount())->toBe(1)
        ->and($dispatch->fresh()->status)->toBe(LegalWatchDispatch::STATUS_DELIVERED);
});

it('n\'écrit rien lorsque le rattrapage tourne en simulation', function () {
    $reader = User::factory()->create();
    $document = publishableDocument();

    DB::table('legal_documents')->where('id', $document->id)->update([
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'watch_notified_at' => now(),
    ]);

    $dispatch = LegalWatchDispatch::create([
        'document_ids' => [$document->id],
        'document_count' => 1,
        'status' => LegalWatchDispatch::STATUS_PENDING,
    ]);

    $this->artisan('mibeko:retry-legal-watch', ['--older-than' => 0, '--dry-run' => true])
        ->assertSuccessful();

    expect(Notification::where('user_id', $reader->id)->count())->toBe(0)
        ->and($dispatch->fresh()->status)->toBe(LegalWatchDispatch::STATUS_PENDING);
});

it('active le canal push de la veille par défaut, et lui seul', function () {
    $defaults = UserSetting::defaultNotificationPreferences();

    // La veille légale est la raison d'être des notifications de l'app : elle
    // ne doit pas dépendre d'un réglage que personne ne va chercher.
    expect($defaults[UserSetting::TYPE_NEW_DOCUMENT]['push'])->toBeTrue();

    // Les autres types restent en opt-in explicite.
    foreach (UserSetting::NOTIFICATION_TYPES as $type) {
        if ($type === UserSetting::TYPE_NEW_DOCUMENT) {
            continue;
        }
        expect($defaults[$type]['push'])->toBeFalse("le type {$type} ne doit pas pousser par défaut");
    }
});

it('pousse à un utilisateur qui n\'a jamais touché à ses préférences', function () {
    // Compte ordinaire : sa matrice est celle persistée à la création.
    $subscriber = User::factory()->create();
    $subscriber->settingsOrCreate();

    $device = Device::factory()->ownedBy($subscriber)->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    expect(Notification::where('user_id', $subscriber->id)->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && $request['message']['token'] === $device->push_token);
});

it('respecte un refus explicite malgré le nouveau défaut', function () {
    $optedOut = User::factory()->create();
    $preferences = UserSetting::defaultNotificationPreferences();
    $preferences[UserSetting::TYPE_NEW_DOCUMENT]['push'] = false;
    $optedOut->settingsOrCreate()->update(['notification_preferences' => $preferences]);

    $device = Device::factory()->ownedBy($optedOut)->create();
    $document = publishableDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
        && $request['message']['token'] === $device->push_token);
});
