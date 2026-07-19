<?php

use App\Models\NewsletterSubscription;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Inscription à la newsletter (`POST /api/v1/newsletter-subscriptions`).
 *
 * Contrat inter-dépôts : {email, source?} → 204 ; 422 si e-mail invalide ;
 * idempotent (même e-mail → 204 sans doublon) ; route publique ; aucun envoi
 * d'e-mail. La limitation de débit est vérifiée à part.
 */
it('inscrit une adresse valide et répond 204', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', [
        'email' => 'lecteur@example.cg',
        'source' => 'home',
    ])->assertNoContent();

    $this->assertDatabaseHas('newsletter_subscriptions', [
        'email' => 'lecteur@example.cg',
        'source' => 'home',
    ]);
});

it('accepte l\'inscription sans source (champ optionnel)', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', [
        'email' => 'sans-source@example.cg',
    ])->assertNoContent();

    $this->assertDatabaseHas('newsletter_subscriptions', [
        'email' => 'sans-source@example.cg',
        'source' => null,
    ]);
});

it('rejette une adresse e-mail invalide (422)', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', [
        'email' => 'pas-un-email',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejette une requête sans e-mail (422)', function () {
    $this->postJson('/api/v1/newsletter-subscriptions', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('est idempotent : une même adresse ne crée pas de doublon et répond 204', function () {
    // On neutralise le throttle pour enchaîner deux requêtes identiques.
    $this->withoutMiddleware(ThrottleRequests::class);

    $payload = ['email' => 'unique@example.cg', 'source' => 'footer'];

    $this->postJson('/api/v1/newsletter-subscriptions', $payload)->assertNoContent();
    $this->postJson('/api/v1/newsletter-subscriptions', $payload)->assertNoContent();

    expect(NewsletterSubscription::where('email', 'unique@example.cg')->count())->toBe(1);
});

it('normalise l\'e-mail en minuscules pour l\'unicité', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'Mixte@Example.CG'])->assertNoContent();
    $this->postJson('/api/v1/newsletter-subscriptions', ['email' => 'mixte@example.cg'])->assertNoContent();

    expect(NewsletterSubscription::count())->toBe(1)
        ->and(NewsletterSubscription::first()->email)->toBe('mixte@example.cg');
});

it('est limité en débit (429 après trop de requêtes)', function () {
    // La route est publique : on borne les inscriptions par IP. Sans neutraliser
    // le throttle, on vérifie qu'un flot de requêtes finit par être refusé.
    $throttled = false;

    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/v1/newsletter-subscriptions', [
            'email' => "flood{$i}@example.cg",
        ]);

        if ($response->getStatusCode() === 429) {
            $throttled = true;
            break;
        }
    }

    expect($throttled)->toBeTrue();
});
