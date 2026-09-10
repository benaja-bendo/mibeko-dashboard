<?php

use App\Models\ContactMessage;
use App\Models\NewsletterSubscription;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin'));
});

it('réserve la boîte de réception et les exports aux administrateurs', function (string $path) {
    $this->getJson('/api/v1/admin/'.$path)->assertUnauthorized();
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('editor'));
    $this->actingAs($user)->getJson('/api/v1/admin/'.$path)->assertForbidden();
})->with(['messages', 'newsletter-subscriptions', 'newsletter-subscriptions/export']);

it('rapproche les adresses sans tenir compte de la casse et masque les données techniques', function () {
    $user = User::factory()->create(['email' => 'awa@example.cg']);
    ContactMessage::create(['name' => 'Awa', 'email' => 'AWA@example.cg', 'profile' => 'professionnel', 'message' => "Bonjour\nUne question", 'ip_address' => '192.0.2.1', 'user_agent' => 'Secret technique']);
    ContactMessage::create(['name' => 'Blaise', 'email' => 'blaise@example.cg', 'message' => 'Sans compte', 'handled' => true]);
    $this->actingAs($this->admin)->getJson('/api/v1/admin/messages?handled=0')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.account.id', $user->id)
        ->assertJsonPath('data.0.message', "Bonjour\nUne question")
        ->assertJsonMissingPath('data.0.ip_address')->assertJsonMissingPath('data.0.user_agent')
        ->assertJsonMissingPath('data.0.account.roles');
    $this->getJson('/api/v1/admin/messages?handled=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.account', null);
    $this->getJson('/api/v1/admin/messages')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/messages?handled=invalid')->assertUnprocessable();
});

it('marque et rouvre un message en actualisant le compteur sans modifier son contenu', function () {
    $contact = ContactMessage::create(['name' => 'Awa', 'email' => 'awa@example.cg', 'message' => 'Demande originale']);
    $path = '/api/v1/admin/messages/'.$contact->id;
    $this->patchJson($path, ['handled' => true])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->patchJson($path, ['handled' => true])->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/v1/admin/overview')->assertJsonPath('data.attention.unhandled_contacts', 1);
    $this->patchJson($path, ['handled' => true, 'message' => 'Écrasé'])->assertOk()->assertJsonPath('data.handled', true);
    $this->patchJson($path, ['handled' => true])->assertOk();
    $this->getJson('/api/v1/admin/overview')->assertJsonPath('data.attention.unhandled_contacts', 0);
    expect($contact->fresh()->message)->toBe('Demande originale');
    $this->patchJson($path, ['handled' => false])->assertOk();
    $this->getJson('/api/v1/admin/overview')->assertJsonPath('data.attention.unhandled_contacts', 1);
    $this->patchJson($path, [])->assertUnprocessable();
    $this->patchJson($path, ['handled' => 'yes'])->assertUnprocessable();
    $this->patchJson('/api/v1/admin/messages/'.fake()->uuid(), ['handled' => true])->assertNotFound();
});

it('pagine les messages et les abonnés et exporte tous les abonnés en CSV sûr', function () {
    for ($index = 0; $index < 21; $index++) {
        ContactMessage::create(['name' => 'Contact '.$index, 'email' => "contact{$index}@example.cg", 'message' => 'Bonjour']);
        NewsletterSubscription::create(['email' => "abonne{$index}@example.cg", 'source' => $index === 0 ? '=HYPERLINK("https://example.cg")' : "Site; accueil\nCongo"]);
    }
    $this->actingAs($this->admin)->getJson('/api/v1/admin/messages')->assertJsonCount(20, 'data')->assertJsonPath('pagination.total', 21);
    $this->getJson('/api/v1/admin/messages?page=2')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/newsletter-subscriptions')->assertJsonCount(20, 'data')->assertJsonPath('pagination.total', 21);
    $this->getJson('/api/v1/admin/newsletter-subscriptions?page=2')->assertJsonCount(1, 'data');
    $response = $this->get('/api/v1/admin/newsletter-subscriptions/export')->assertOk()->assertDownload();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, substr($response->streamedContent(), 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);
    expect($rows)->toHaveCount(22);
    expect($rows[0])->toBe(['E-mail', 'Source', 'Date d’inscription']);
    $sources = array_column(array_slice($rows, 1), 1);
    expect($sources)->toContain("'=HYPERLINK(\"https://example.cg\")", "Site; accueil\nCongo");
});
