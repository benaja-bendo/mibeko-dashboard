<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Mocke la façade Pdf de bout en bout, avec une assertion sur les items rendus. */
function mockPdfExport(?Closure $loadViewAssertion = null): void
{
    $loadView = Pdf::shouldReceive('loadView')->once();

    if ($loadViewAssertion) {
        $loadView->withArgs($loadViewAssertion);
    }

    $loadView->andReturnSelf();

    Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
    Pdf::shouldReceive('setOption')->twice()->andReturnSelf();
    Pdf::shouldReceive('output')->once()->andReturn('fake-pdf-content');
}

it('can export a dossier to PDF', function () {
    mockPdfExport();

    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create([
        'document_id' => $document->id,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/dossiers/export-pdf', [
            'title' => 'Mon Dossier',
            'description' => 'Description du dossier',
            'items' => [
                [
                    'type' => 'article',
                    'id' => $article->id,
                    'note' => 'Note importante',
                ],
            ],
        ]);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('validates dossier export request', function () {
    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/dossiers/export-pdf', [
            // missing title
            'items' => [],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'items']);
});

it('laisse un invité exporter, mais uniquement du corpus publié', function () {
    $draftDocument = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $draftArticle = Article::factory()->create(['document_id' => $draftDocument->id]);

    $publishedDocument = LegalDocument::factory()->create();
    $publishedArticle = Article::factory()->create(['document_id' => $publishedDocument->id]);

    mockPdfExport(function (string $view, array $data) use ($draftArticle, $publishedArticle) {
        $renderedIds = collect($data['items'])->pluck('content.id');

        return $renderedIds->contains($publishedArticle->id)
            && ! $renderedIds->contains($draftArticle->id);
    });

    // Les dossiers de l'app mobile sont locaux et exportables sans compte :
    // fermer la route casserait la flotte déjà publiée. La garde anti-fuite
    // porte sur le contenu (corpus publié seulement), pas sur l'accès.
    $this->postJson('/api/v1/dossiers/export-pdf', [
        'title' => 'Mon Dossier',
        'items' => [
            ['type' => 'article', 'id' => $draftArticle->id, 'note' => null],
            ['type' => 'article', 'id' => $publishedArticle->id, 'note' => null],
        ],
    ])
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exclut les articles de documents non publiés de l\'export d\'un compte standard', function () {
    $draftDocument = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $draftArticle = Article::factory()->create(['document_id' => $draftDocument->id]);

    $publishedDocument = LegalDocument::factory()->create();
    $publishedArticle = Article::factory()->create(['document_id' => $publishedDocument->id]);

    mockPdfExport(function (string $view, array $data) use ($draftArticle, $publishedArticle) {
        $renderedIds = collect($data['items'])->pluck('content.id');

        return $renderedIds->contains($publishedArticle->id)
            && ! $renderedIds->contains($draftArticle->id);
    });

    // Les ids étant libres dans la requête, un compte sans rôle éditorial ne
    // doit pas pouvoir exfiltrer un brouillon : l'item draft est filtré,
    // l'article publié est exporté normalement.
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/dossiers/export-pdf', [
            'title' => 'Mon Dossier',
            'items' => [
                ['type' => 'article', 'id' => $draftArticle->id, 'note' => null],
                ['type' => 'article', 'id' => $publishedArticle->id, 'note' => null],
            ],
        ])
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('laisse un éditeur exporter des articles de documents non publiés', function () {
    $draftDocument = LegalDocument::factory()->create(['curation_status' => 'draft']);
    $draftArticle = Article::factory()->create(['document_id' => $draftDocument->id]);

    mockPdfExport(function (string $view, array $data) use ($draftArticle) {
        return collect($data['items'])->pluck('content.id')->contains($draftArticle->id);
    });

    $editor = User::factory()->create();
    $editor->assignRole(Role::findOrCreate('editor'));

    // Le dashboard éditeur travaille légitimement sur les brouillons : même
    // départage que les endpoints de lecture (GuardsUnpublishedDocuments).
    $this->actingAs($editor)
        ->postJson('/api/v1/dossiers/export-pdf', [
            'title' => 'Dossier de travail',
            'items' => [
                ['type' => 'article', 'id' => $draftArticle->id, 'note' => null],
            ],
        ])
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});
