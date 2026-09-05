<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

/**
 * Couvre l'export PDF Mibeko (`/legal-documents/{id}/export` et
 * `/articles/{id}/export`) : génération effective du PDF avec la mise en
 * page Mibeko (couverture, sommaire, corps) sans erreur de rendu Blade, et
 * mise en cache (voir DocumentExportPdfService) du rendu par version.
 *
 * Ces routes sont verrouillées derrière l'entitlement `export`
 * (mibeko-dashboard#86) : tous les appels ici passent par un compte Pro
 * (Bearer Sanctum) — l'entitlement elle-même est couverte séparément par
 * LegalDocumentExportEntitlementTest.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Storage::fake('s3');

    Role::findOrCreate('user_pro');
    $this->pro = User::factory()->create();
    $this->pro->assignRole('user_pro');

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    $institution = Institution::factory()->create(['nom' => 'Ministère de la Justice']);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi de test sur l\'export PDF',
        'curation_status' => 'published',
        'legal_scope' => 'national',
        'institution_id' => $institution->id,
        'date_publication' => '2024-05-01',
    ]);

    $node = StructureNode::factory()->create([
        'document_id' => $this->document->id,
        'type_unite' => 'Titre',
        'numero' => 'I',
        'titre' => 'Dispositions générales',
        'tree_path' => '1',
    ]);

    $this->article = Article::factory()->create([
        'document_id' => $this->document->id,
        'parent_node_id' => $node->id,
        'numero_article' => '1',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Le présent texte régit l\'export PDF de la plateforme.',
        'validity_period' => '[2024-05-01,)',
    ]);

    // Article hors structure (dispositions complémentaires).
    $orphan = Article::factory()->create([
        'document_id' => $this->document->id,
        'parent_node_id' => null,
        'numero_article' => '2',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $orphan->id,
        'contenu_texte' => 'Disposition complémentaire de test.',
        'validity_period' => '[2024-05-01,)',
    ]);
});

it('exporte un document complet en PDF', function () {
    $response = $this->actingAs($this->pro)->get("/api/v1/legal-documents/{$this->document->id}/export");

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF');
});

it('met le PDF en cache et le réutilise au lieu de le regénérer', function () {
    $this->actingAs($this->pro)->get("/api/v1/legal-documents/{$this->document->id}/export")->assertStatus(200);

    $directory = "exports/documents/{$this->document->id}";
    $files = Storage::disk('s3')->files($directory);
    expect($files)->toHaveCount(1);

    // Remplace le contenu mis en cache par un marqueur : si le second appel
    // régénérait au lieu de servir le cache, la réponse serait un nouveau
    // PDF (`%PDF...`), pas ce marqueur.
    Storage::disk('s3')->put($files[0], 'CACHE-HIT-MARKER');

    $response = $this->actingAs($this->pro)->get("/api/v1/legal-documents/{$this->document->id}/export");

    $response->assertStatus(200);
    expect($response->streamedContent())->toBe('CACHE-HIT-MARKER');
});

it('régénère le PDF en cache quand le contenu du document change', function () {
    $this->actingAs($this->pro)->get("/api/v1/legal-documents/{$this->document->id}/export")->assertStatus(200);

    $directory = "exports/documents/{$this->document->id}";
    $pathBefore = Storage::disk('s3')->files($directory)[0];

    // Cascade ArticleVersion → Article → LegalDocument ($touches) : modifier
    // le contenu d'une version bouge document->updated_at, donc la clé de
    // cache.
    $this->article->activeVersion->update(['contenu_texte' => 'Texte modifié après publication.']);

    // `updated_at` est en précision seconde (timestamp(0) en base) : les deux
    // sauvegardes de ce test tombent dans la même seconde. On force un écart
    // pour simuler le passage du temps réel entre deux vraies éditions,
    // plutôt que d'avoir un test flaky dépendant de la vitesse d'exécution.
    $this->document->refresh();
    $this->document->forceFill(['updated_at' => $this->document->updated_at->addSecond()])->saveQuietly();

    $this->actingAs($this->pro)->get("/api/v1/legal-documents/{$this->document->id}/export")->assertStatus(200);

    $filesAfter = Storage::disk('s3')->files($directory);
    expect($filesAfter)->toHaveCount(1)
        ->and($filesAfter[0])->not->toBe($pathBefore);
});

it('exporte un article seul en PDF', function () {
    $response = $this->actingAs($this->pro)->get("/api/v1/articles/{$this->article->id}/export");

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('renvoie 404 pour un document inexistant', function () {
    $this->actingAs($this->pro)->get('/api/v1/legal-documents/00000000-0000-0000-0000-000000000000/export')
        ->assertStatus(404);
});
