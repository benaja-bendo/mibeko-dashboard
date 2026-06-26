<?php

use App\Models\LegalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Garantit que tout document publié finit par avoir un slug, même ceux insérés
 * directement en base par le pipeline d'ingestion Python (sans passer par les
 * hooks Eloquent). Sans slug, un texte publié est silencieusement invisible du
 * site vitrine (qui filtre sur la présence d'un slug).
 *
 * Deux filets complémentaires sont couverts ici :
 *  - la commande de backfill `mibeko:backfill-document-slugs` (planifiée) ;
 *  - le hook `saving` qui répare le slug dès qu'un document slugless est touché
 *    via Eloquent (typiquement au moment de la publication).
 */

/**
 * Insère un document « à la Python » : écriture SQL brute, sans slug, sans
 * déclencher le moindre événement Eloquent.
 */
function insertSluglessDocument(string $title, string $curationStatus = 'published'): string
{
    $id = (string) Str::uuid();

    DB::table('legal_documents')->insert([
        'id' => $id,
        'titre_officiel' => $title,
        'slug' => null,
        'curation_status' => $curationStatus,
        'document_role' => 'FLUX',
        'legal_scope' => 'national',
        'statut' => 'vigueur',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('génère le slug des documents ingérés sans slug', function () {
    $id = insertSluglessDocument('Code Minier');

    $this->artisan('mibeko:backfill-document-slugs')->assertSuccessful();

    expect(LegalDocument::find($id)->slug)->toBe('code-minier');
});

it('déduplique les slugs lors du backfill de documents homonymes', function () {
    $first = insertSluglessDocument('Code des Hydrocarbures');
    $second = insertSluglessDocument('Code des Hydrocarbures');

    $this->artisan('mibeko:backfill-document-slugs')->assertSuccessful();

    expect([
        LegalDocument::find($first)->slug,
        LegalDocument::find($second)->slug,
    ])->toEqualCanonicalizing(['code-des-hydrocarbures', 'code-des-hydrocarbures-2']);
});

it('est idempotente et ne réécrit pas un slug existant', function () {
    $id = insertSluglessDocument('Code de la Route');

    $this->artisan('mibeko:backfill-document-slugs')->assertSuccessful();
    $slug = LegalDocument::find($id)->slug;

    $this->artisan('mibeko:backfill-document-slugs')
        ->expectsOutputToContain('Aucun document sans slug')
        ->assertSuccessful();

    expect(LegalDocument::find($id)->slug)->toBe($slug);
});

it('n\'écrit rien en mode dry-run', function () {
    $id = insertSluglessDocument('Code Forestier');

    $this->artisan('mibeko:backfill-document-slugs', ['--dry-run' => true])
        ->expectsOutputToContain('1 document(s) sans slug seraient traités')
        ->assertSuccessful();

    expect(LegalDocument::find($id)->slug)->toBeNull();
});

it('répare le slug d\'un document slugless dès sa publication via Eloquent', function () {
    $id = insertSluglessDocument('Loi de Finances', curationStatus: 'draft');

    LegalDocument::find($id)->update(['curation_status' => 'published']);

    expect(LegalDocument::find($id)->slug)->toBe('loi-de-finances');
});
