<?php

use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use App\Services\Curation\JeuDeDetecteurs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('controls a single document targeted by --document, ignoring --limit', function () {
    $document = LegalDocument::factory()->create();
    $autre = LegalDocument::factory()->create();

    $this->artisan('mibeko:controler-documents', ['--document' => $document->id, '--limit' => 0])
        ->assertExitCode(0);

    expect(DocumentControleRun::where('document_id', $document->id)->count())->toBe(1)
        ->and(DocumentControleRun::where('document_id', $autre->id)->count())->toBe(0);
});

it('fails clearly when --document does not match any document', function () {
    $this->artisan('mibeko:controler-documents', ['--document' => (string) Str::uuid()])
        ->assertExitCode(1);
});

it('controls a live document that has never been controlled', function () {
    $document = LegalDocument::factory()->create();

    $this->artisan('mibeko:controler-documents', ['--limit' => 10])->assertExitCode(0);

    expect(DocumentControleRun::where('document_id', $document->id)->where('version_jeu', JeuDeDetecteurs::VERSION)->exists())->toBeTrue();
});

it('does not recontrol a document whose last run is not older than its last update', function () {
    $document = LegalDocument::factory()->create();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'version_jeu' => JeuDeDetecteurs::VERSION,
        'date' => now(),
    ]);
    // Le run est postérieur à la dernière modification du document (créé
    // juste après lui) : rien à refaire.

    $this->artisan('mibeko:controler-documents', ['--limit' => 10])->assertExitCode(0);

    expect(DocumentControleRun::where('document_id', $document->id)->count())->toBe(1);
});

it('recontrols a document touched after its last run', function () {
    $document = LegalDocument::factory()->create();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'version_jeu' => JeuDeDetecteurs::VERSION,
        'date' => now()->subDay(),
    ]);
    $document->touch(); // updated_at devient postérieur au dernier run

    $this->artisan('mibeko:controler-documents', ['--limit' => 10])->assertExitCode(0);

    expect(DocumentControleRun::where('document_id', $document->id)->count())->toBe(2);
});

it('recontrols a document whose last run used an older version of the set', function () {
    $document = LegalDocument::factory()->create();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'version_jeu' => 'v2', // jeu déclassé
        'date' => now(),
    ]);

    $this->artisan('mibeko:controler-documents', ['--limit' => 10, '--version-jeu' => JeuDeDetecteurs::VERSION])->assertExitCode(0);

    expect(DocumentControleRun::where('document_id', $document->id)->where('version_jeu', JeuDeDetecteurs::VERSION)->count())->toBe(1);
});

it('stamps the run with a custom --version-jeu label rather than always the current constant', function () {
    $document = LegalDocument::factory()->create();

    $this->artisan('mibeko:controler-documents', ['--document' => $document->id, '--version-jeu' => 'v3-essai'])
        ->assertExitCode(0);

    $run = DocumentControleRun::where('document_id', $document->id)->first();
    expect($run->version_jeu)->toBe('v3-essai');
});

it('--dry-run writes nothing at all, even when an anomaly is found', function () {
    $document = LegalDocument::factory()->create();
    $article = $document->articles()->create(['numero_article' => '12-doublon', 'ordre_affichage' => 1]);
    $article->versions()->create([
        'contenu_texte' => 'Contenu quelconque, suffisamment long.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);

    $this->artisan('mibeko:controler-documents', ['--document' => $document->id, '--dry-run' => true])
        ->expectsOutputToContain('rien n\'a été écrit')
        ->assertExitCode(0);

    expect(DocumentControleRun::count())->toBe(0)
        ->and(CurationFlag::count())->toBe(0);
});

it('--dry-run ignores prior resolved exceptions : it reports the raw anomaly, not the reconciled one', function () {
    // La comparaison à la v2 (critère de clôture de #141) porte sur le
    // comptage BRUT de la requête SQL d'origine, qui n'a jamais connu de
    // mécanisme d'exception — un dry-run qui masquerait une anomalie déjà
    // résolue fausserait la preuve de non-régression du portage.
    $document = LegalDocument::factory()->create();
    $contenu = 'Contenu quelconque, suffisamment long.';
    $article = $document->articles()->create(['numero_article' => '12-doublon', 'ordre_affichage' => 1]);
    $article->versions()->create([
        'contenu_texte' => $contenu,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);
    foreach (['d1_numero_doublon', 'd2_numero_hors_liste_blanche'] as $typeProbleme) {
        CurationFlag::create([
            'document_id' => $document->id,
            'article_id' => $article->id,
            'source' => CurationFlag::SOURCE_CONFORMITE,
            'type_probleme' => $typeProbleme,
            'severity' => CurationFlag::SEVERITY_BLOCKING,
            'description' => 'déjà vu, fidèle à la source',
            'anchor' => ['empreinte' => hash('sha256', $contenu)],
            'resolved' => true,
            'resolved_at' => now(),
        ]);
    }

    $this->artisan('mibeko:controler-documents', ['--document' => $document->id, '--dry-run' => true])
        ->expectsOutputToContain('0 ok, 1 échec')
        ->assertExitCode(0);

    // Toujours 2 : les deux exceptions déjà résolues, rien ajouté par le dry-run.
    expect(CurationFlag::count())->toBe(2);
});

it('respects --limit', function () {
    LegalDocument::factory()->count(3)->create();

    $this->artisan('mibeko:controler-documents', ['--limit' => 2])->assertExitCode(0);

    expect(DocumentControleRun::count())->toBe(2);
});

it('reports nothing to do without error when every live document is already controlled', function () {
    $document = LegalDocument::factory()->create();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'version_jeu' => JeuDeDetecteurs::VERSION,
        'date' => now(),
    ]);

    $this->artisan('mibeko:controler-documents', ['--limit' => 10])
        ->expectsOutputToContain('Aucun document à contrôler')
        ->assertExitCode(0);
});

it('never changes curation_status : a document already validated stays validated even with an open anomaly', function () {
    // Critère de clôture explicite de #141, régression directe du défaut du
    // 14/09 (§ 2.5) : le tampon de contrôle ne doit jamais toucher
    // legal_documents (VALIDATION_INVALIDATING_FIELDS inclut `metadata`).
    $document = LegalDocument::factory()->create(['curation_status' => 'validated']);
    $article = $document->articles()->create(['numero_article' => '12-doublon', 'ordre_affichage' => 1]);
    $article->versions()->create([
        'contenu_texte' => 'Contenu quelconque, suffisamment long pour ne déclencher que le doublon.',
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);

    $this->artisan('mibeko:controler-documents', ['--document' => $document->id])->assertExitCode(0);

    expect(CurationFlag::where('document_id', $document->id)->where('source', CurationFlag::SOURCE_CONFORMITE)->exists())->toBeTrue()
        ->and($document->fresh()->curation_status)->toBe('validated');
});
