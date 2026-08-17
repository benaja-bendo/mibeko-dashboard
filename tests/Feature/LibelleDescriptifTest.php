<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Chantier du 16/08/2026 : les « actes en abrégé » du Journal officiel portent
 * un intitulé réduit au type, au numéro et à la date — fidèle à la source, donc
 * intouchable. Le libellé descriptif, dérivé du corps, s'affiche à côté.
 *
 * La règle que ces tests verrouillent : le libellé ne devient JAMAIS le titre,
 * et rien dans ce chemin n'écrit `titre_officiel`.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('enregistre un libellé descriptif sans toucher au titre officiel', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-240 du 20 juin 2025.',
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'libelle_descriptif' => 'Nomination : directeur général de l\'imprimerie nationale',
            'libelle_descriptif_source' => 'article',
        ])
        ->assertOk()
        ->assertJsonPath('data.libelle_descriptif', 'Nomination : directeur général de l\'imprimerie nationale')
        ->assertJsonPath('data.libelle_descriptif_source', 'article')
        // Le titre officiel reste EXACTEMENT ce que le JO a imprimé.
        ->assertJsonPath('data.titre_officiel', 'Décret n° 2025-240 du 20 juin 2025.');

    expect($document->fresh()->titre_officiel)->toBe('Décret n° 2025-240 du 20 juin 2025.');
});

it('refuse un libellé sans provenance', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'libelle_descriptif' => 'Nomination : préfet du département du Pool',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('libelle_descriptif_source');

    expect($document->fresh()->libelle_descriptif)->toBeNull();
});

it('refuse une provenance hors de la liste', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'libelle_descriptif' => 'Nomination : préfet du département du Pool',
            'libelle_descriptif_source' => 'devine',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('libelle_descriptif_source');
});

it('efface la provenance quand le libellé est retiré', function () {
    $document = LegalDocument::factory()->create([
        'libelle_descriptif' => 'Nomination : préfet du département du Pool',
        'libelle_descriptif_source' => 'manuel',
    ]);

    // Sans remise à null de la provenance, la contrainte CHECK
    // `legal_documents_libelle_descriptif_source_check` ferait échouer l'écriture.
    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'libelle_descriptif' => null,
        ])
        ->assertOk();

    expect($document->fresh()->libelle_descriptif)->toBeNull()
        ->and($document->fresh()->libelle_descriptif_source)->toBeNull();
});

it('interdit en base une provenance sans libellé', function () {
    $document = LegalDocument::factory()->create();

    expect(fn () => DB::table('legal_documents')
        ->where('id', $document->id)
        ->update(['libelle_descriptif_source' => 'article'])
    )->toThrow(QueryException::class);
});

it('trouve un acte en abrégé par son libellé, que le titre ne contient pas', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-340 du 7 août 2025.',
        'libelle_descriptif' => 'Nomination : président du Conseil supérieur de la liberté de communication',
        'libelle_descriptif_source' => 'article',
        'curation_status' => 'published',
    ]);

    $this->actingAs($this->editor)
        ->getJson('/api/v1/legal-documents/search?q=liberté de communication')
        ->assertOk()
        ->assertJsonPath('data.0.id', $document->id);
});

it('expose le libellé au catalogue mobile sans en faire le titre', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-340 du 7 août 2025.',
        'libelle_descriptif' => 'Nomination : président du Conseil supérieur',
        'libelle_descriptif_source' => 'article',
        'curation_status' => 'published',
    ]);

    // Le scope `published()` exige au moins un article.
    Article::factory()->create(['document_id' => $document->id]);

    $reponse = $this->actingAs($this->editor)
        ->getJson('/api/v1/catalog')
        ->assertOk();

    $entree = collect($reponse->json('data.resources'))->firstWhere('id', $document->id);

    expect($entree['title'])->toBe('Décret n° 2025-340 du 7 août 2025.')
        ->and($entree['descriptive_label'])->toBe('Nomination : président du Conseil supérieur');
});

it('propose des libellés sans rien écrire en base', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-340 du 7 août 2025.',
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create(['document_id' => $document->id, 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'M. MILANDOU NSONGA (Médard) est nommé président du Conseil supérieur '
            .'de la liberté de communication. Il percevra les indemnités prévues.',
    ]);

    $sortie = tempnam(sys_get_temp_dir(), 'libelles').'.json';

    $this->artisan('mibeko:proposer-libelles', [
        '--connection' => 'pgsql',
        '--statut' => 'published',
        '--out' => $sortie,
    ])->assertSuccessful();

    $propositions = json_decode((string) file_get_contents($sortie), true);

    expect($propositions)->toHaveCount(1)
        ->and($propositions[0]['id'])->toBe($document->id)
        ->and($propositions[0]['libelle'])
        ->toBe('Nomination : président du Conseil supérieur de la liberté de communication')
        ->and($propositions[0]['confiance'])->toBe('haute');

    // Le contrat de la commande : elle PROPOSE, elle n'écrit pas.
    expect($document->fresh()->libelle_descriptif)->toBeNull()
        ->and($document->fresh()->titre_officiel)->toBe('Décret n° 2025-340 du 7 août 2025.');

    unlink($sortie);
});

it('ignore les intitulés dont le corps a été avalé dans le titre', function () {
    $document = LegalDocument::factory()->create([
        // Finit par une date, donc pris par la condition SQL du détecteur —
        // mais ce n'est pas un acte en abrégé, c'est un titre à réparer.
        'titre_officiel' => 'Arrêté n° 2084 du 15 avril 2009. Mlle MAKAMBO (Anne Faustine), '
            .'secrétaire d\'administration, est promue au 2e échelon pour compter du 21 février 2006.',
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create(['document_id' => $document->id, 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => 'Le présent arrêté prend effet.']);

    $sortie = tempnam(sys_get_temp_dir(), 'libelles').'.json';

    $this->artisan('mibeko:proposer-libelles', [
        '--connection' => 'pgsql',
        '--statut' => 'published',
        '--out' => $sortie,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($sortie), true))->toBe([]);

    unlink($sortie);
});

it('refuse un lot qui porterait un titre au lieu d\'un libellé', function () {
    Http::fake();

    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    file_put_contents($lot, json_encode([
        ['id' => (string) Str::uuid(), 'titre' => 'Décret n° 2025-240 du 20 juin 2025 portant nomination'],
    ]));

    $this->artisan('mibeko:appliquer-libelles', ['--liste' => $lot, '--execute' => true])
        ->assertFailed();

    Http::assertNothingSent();

    unlink($lot);
});

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    file_put_contents($lot, json_encode([
        ['id' => (string) Str::uuid(), 'libelle' => 'Nomination : préfet du Pool', 'confiance' => 'haute'],
    ]));

    $this->artisan('mibeko:appliquer-libelles', ['--liste' => $lot])
        ->assertSuccessful();

    Http::assertNothingSent();

    unlink($lot);
});
