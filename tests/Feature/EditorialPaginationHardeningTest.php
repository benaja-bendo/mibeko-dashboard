<?php

use App\Models\CurationFlag;
use App\Models\DocumentRelation;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\PublicationChecklist;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use OwenIt\Auditing\Models\Audit;

/**
 * Taille de page des routes éditoriales et admin (dashboard#191).
 *
 * Suite de l'audit du 23/09/2026 (#179), qui n'avait borné `per_page` que sur
 * les routes publiques. Ici, `per_page=-1` rendait la table entière — le query
 * builder ignore en silence une LIMIT négative, et `?: 20` laisse passer -1 —
 * et `document-relations` comme `admin/flags` n'avaient même pas de plafond.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    // Deux appels par route dépassent le throttle `api`, réduit en test.
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/*
 * Chaque entrée : de quoi créer $n lignes sur la route (renvoie l'URL, prête
 * à recevoir `per_page`), et la taille de page par défaut de cette route.
 */
dataset('routes éditoriales et admin', [
    'legal-documents/{id}/publication-checklists' => [function (int $n): string {
        $document = LegalDocument::factory()->create();
        Collection::times($n, fn () => PublicationChecklist::create([
            'document_id' => $document->id,
            'target_status' => LegalDocument::STATUS_PUBLISHED,
            'outcome' => 'blocked',
            'criteria' => [],
        ]));

        return "/api/v1/legal-documents/{$document->id}/publication-checklists?";
    }, 20],
    'review-queue' => [function (int $n): string {
        LegalDocument::factory()->count($n)->create(['curation_status' => LegalDocument::STATUS_REVIEW]);

        return '/api/v1/review-queue?';
    }, 20],
    'document-relations' => [function (int $n): string {
        DocumentRelation::factory()->count($n)->candidate()->create();

        return '/api/v1/document-relations?';
    }, 20],
    'admin/users' => [function (int $n): string {
        User::factory()->count($n)->create();

        return '/api/v1/admin/users?';
    }, 20],
    'admin/audits' => [function (int $n): string {
        Collection::times($n, fn () => Audit::create([
            'event' => 'updated',
            'auditable_type' => Institution::class,
            'auditable_id' => (string) Str::uuid(),
            'old_values' => ['nom' => 'Ancien'],
            'new_values' => ['nom' => 'Nouveau'],
        ]));

        return '/api/v1/admin/audits?';
    }, 25],
    'admin/flags' => [function (int $n): string {
        $document = LegalDocument::factory()->create();
        Collection::times($n, fn () => CurationFlag::create([
            'document_id' => $document->id,
            'type_probleme' => 'erreur',
            'resolved' => false,
        ]));

        return '/api/v1/admin/flags?';
    }, 20],
]);

it('ne rend jamais toute la table quand per_page est négatif', function (Closure $seed, int $default) {
    $url = $seed($default + 1);

    $response = $this->actingAs($this->admin)->getJson($url.'per_page=-1')->assertOk();

    expect($response->json('data'))->toHaveCount($default)
        ->and($response->json('pagination.per_page'))->toBe($default);
})->with('routes éditoriales et admin');

it('plafonne per_page à 100', function (Closure $seed) {
    $url = $seed(0);

    $response = $this->actingAs($this->admin)->getJson($url.'per_page=5000')->assertOk();

    expect($response->json('pagination.per_page'))->toBe(100);
})->with('routes éditoriales et admin');

it('retombe sur la taille par défaut pour un per_page non entier', function (Closure $seed, int $default) {
    $url = $seed(0);

    $response = $this->actingAs($this->admin)->getJson($url.'per_page=abc')->assertOk();

    expect($response->json('pagination.per_page'))->toBe($default);
})->with('routes éditoriales et admin');
