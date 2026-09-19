<?php

use App\Console\Commands\DetecterCitationsCommand;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use App\Services\Curation\NumeroActeExtractor;
use App\Services\Operations\OperationsClasseUne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Chantier du 19/09/2026 : l'URL canonique d'un texte dérive désormais de sa
 * CITATION — type, numéro, date de signature — et non plus de son titre.
 *
 * Ce que ces tests verrouillent : le numéro n'est jamais deviné, il ne vient
 * jamais d'un acte CITÉ par le titre, il ne s'écrit pas sans provenance, et
 * aucune écriture de numéro ne touche `titre_officiel` ni le slug.
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

    // Types cités par les scénarios de citation partagée : la clé étrangère
    // `legal_documents.type_code` exige qu'ils existent.
    DocumentType::firstOrCreate(['code' => 'DEC'], ['nom' => 'Décret']);
    DocumentType::firstOrCreate(['code' => 'ARR'], ['nom' => 'Arrêté']);

    $this->extracteur = new NumeroActeExtractor;
});

// ── La règle de forme ────────────────────────────────────────────────────────

it('normalise un numéro tel qu\'il est imprimé au Journal officiel', function (string $brut, ?string $attendu) {
    expect($this->extracteur->normaliser($brut))->toBe($attendu);
})->with([
    'forme canonique' => ['2025-240', '2025-240'],
    'préfixe n°' => ['n° 2025-240', '2025-240'],
    'espaces autour du tiret' => ['46 - 2014', '46-2014'],
    'tiret demi-cadratin' => ['2025–336', '2025-336'],
    'code de service espacé' => ['4107/CAB 3', '4107/CAB3'],
    'suffixe bis' => ['14 bis/59', '14bis/59'],
    'point final du titre' => ['3497.', '3497'],
    'casse du code de service conservée' => ['80-550/ETR-SGDAAPDP', '80-550/ETR-SGDAAPDP'],
    'vide' => ['   ', null],
]);

it('refuse une forme non attestée', function (string $numero) {
    expect($this->extracteur->estConforme($numero))->toBeFalse();
})->with([
    'préfixe conservé' => ['n°2025-240'],
    'espace interne' => ['2025 240'],
    'commence par une lettre' => ['CAB-3497'],
    'parenthèses' => ['3497(bis)'],
    'trop long' => [str_repeat('9', 61)],
]);

// ── L'extraction depuis le titre ─────────────────────────────────────────────

it('extrait le numéro d\'un acte en abrégé', function () {
    $extrait = $this->extracteur->extraire('Décret n° 2025-240 du 20 juin 2025.');

    expect($extrait['numero'])->toBe('2025-240')
        ->and($extrait['confiance'])->toBe('haute')
        ->and($extrait['motif_rejet'])->toBeNull();
});

it('extrait le numéro d\'un titre qui porte son objet', function () {
    $extrait = $this->extracteur->extraire(
        'Loi n° 21-2018 du 13 juin 2018 fixant les règles d\'occupation du domaine public',
    );

    expect($extrait['numero'])->toBe('21-2018');
});

it('marque à vérifier un numéro qui porte un code de service', function () {
    $extrait = $this->extracteur->extraire('Arrêté n° 4107/CAB 3 du 12 mars 1959 portant nomination');

    expect($extrait['numero'])->toBe('4107/CAB3')
        ->and($extrait['confiance'])->toBe('a_verifier');
});

it('prend le numéro de l\'acte, pas celui du texte qu\'il modifie', function () {
    $extrait = $this->extracteur->extraire(
        'Décret n° 2011-491 du 29 juillet 2011 modifiant le décret n° 2007-274 du 21 mai 2007',
    );

    expect($extrait['numero'])->toBe('2011-491');
});

it('ne prend jamais le numéro d\'un acte seulement cité', function () {
    // Le seul « n° » du titre est celui du décret CITÉ : le prendre donnerait
    // à cet arrêté l'identité du décret 2007-274, donc son URL.
    $extrait = $this->extracteur->extraire(
        'Arrêté portant application du décret n° 2007-274 du 21 mai 2007',
    );

    expect($extrait['numero'])->toBeNull()
        ->and($extrait['motif_rejet'])->toBe('numero_d_un_autre_acte');
});

it('écarte un titre commençant par une minuscule', function () {
    // Fragment de phrase promu en document par le découpage : sa remédiation
    // est une fusion, pas une saisie de numéro.
    $extrait = $this->extracteur->extraire('décret en Conseil des ministres n° 2007-274 susvisé');

    expect($extrait['motif_rejet'])->toBe('titre_non_capitalise');
});

it('n\'invente aucun numéro quand le titre n\'en porte pas', function () {
    $extrait = $this->extracteur->extraire('Avis de recrutement dans la fonction publique');

    expect($extrait['numero'])->toBeNull()
        ->and($extrait['motif_rejet'])->toBe('numero_absent');
});

it('accepte une désignation composée', function () {
    $extrait = $this->extracteur->extraire('Décret en Conseil des ministres n° 2025-279 du 30 juin 2025');

    expect($extrait['numero'])->toBe('2025-279');
});

// ── Le champ et sa provenance ────────────────────────────────────────────────

it('enregistre un numéro d\'acte sans toucher au titre officiel', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-240 du 20 juin 2025.',
        'slug' => 'decret-n-2025-240-du-20-juin-2025',
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'numero_acte' => '2025-240',
            'numero_acte_source' => 'titre',
        ])
        ->assertOk()
        ->assertJsonPath('data.numero_acte', '2025-240')
        ->assertJsonPath('data.numero_acte_source', 'titre')
        ->assertJsonPath('data.titre_officiel', 'Décret n° 2025-240 du 20 juin 2025.');

    $fraichement = $document->fresh();

    expect($fraichement->titre_officiel)->toBe('Décret n° 2025-240 du 20 juin 2025.')
        ->and($fraichement->slug)->toBe('decret-n-2025-240-du-20-juin-2025');
});

it('refuse un numéro sans provenance', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['numero_acte' => '2025-240'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('numero_acte_source');
});

it('refuse une provenance hors de la liste', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'numero_acte' => '2025-240',
            'numero_acte_source' => 'devine',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('numero_acte_source');
});

it('refuse un numéro qui n\'est pas en forme normalisée', function () {
    $document = LegalDocument::factory()->create();

    // Normaliser silencieusement serait pire que refuser : « 2025 240 »
    // deviendrait « 2025240 », un numéro qui n'existe pas.
    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'numero_acte' => 'n° 2025 240',
            'numero_acte_source' => 'manuel',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('numero_acte');
});

it('efface la provenance quand le numéro est retiré', function () {
    $document = LegalDocument::factory()->create([
        'numero_acte' => '2025-240',
        'numero_acte_source' => 'titre',
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['numero_acte' => null])
        ->assertOk()
        ->assertJsonPath('data.numero_acte', null)
        ->assertJsonPath('data.numero_acte_source', null);

    expect($document->fresh()->numero_acte_source)->toBeNull();
});

it('interdit en base une provenance sans numéro', function () {
    $document = LegalDocument::factory()->create();

    expect(fn () => DB::table('legal_documents')
        ->where('id', $document->id)
        ->update(['numero_acte_source' => 'titre']))
        ->toThrow(QueryException::class);
});

// ── Les croisements avec les champs structurés ───────────────────────────────

it('accepte « numéro » en toutes lettres et lit le point entre codes comme un séparateur', function () {
    expect($this->extracteur->extraire('LOI CONSTITUTIONNELLE NUMERO 1')['numero'])->toBe('1')
        ->and($this->extracteur->normaliser('80-493/MTJ.DGTFP.DFP/21021/15'))->toBe('80-493/MTJ/DGTFP/DFP/21021/15')
        ->and($this->extracteur->normaliser('3334/INT-AG.'))->toBe('3334/INT-AG')
        ->and($this->extracteur->normaliser('numéro 12'))->toBe('12');
});

it('lit le type et la date annoncés par le titre', function () {
    expect($this->extracteur->typeAnnonceParLeTitre('Décret n° 2025-240 du 20 juin 2025.'))->toBe('DEC')
        ->and($this->extracteur->typeAnnonceParLeTitre('ARRETE N° 3497 DU 2 SEPTEMBRE 2025'))->toBe('ARR')
        ->and($this->extracteur->typeAnnonceParLeTitre('Loi constitutionnelle n° 1-2025 du 3 mars 2025'))->toBe('CONST')
        ->and($this->extracteur->typeAnnonceParLeTitre('Loi organique n° 12-2024'))->toBe('LOI')
        ->and($this->extracteur->typeAnnonceParLeTitre('Avis n° 343 de l\'Office des changes'))->toBeNull();

    expect($this->extracteur->dateAnnonceeParLeTitre('Décret n° 2025-240 du 20 juin 2025.'))->toBe('2025-06-20')
        ->and($this->extracteur->dateAnnonceeParLeTitre('Arrêté n° 12 du 1er août 2019 portant…'))->toBe('2019-08-01')
        ->and($this->extracteur->dateAnnonceeParLeTitre('LOI N° 21-2018 DU 13 JUIN 2018'))->toBe('2018-06-13')
        ->and($this->extracteur->dateAnnonceeParLeTitre('Code de la famille de 1984'))->toBeNull();
});

it('ne contredit le type que quand les deux sont sûrs', function () {
    // Une phrase de corps promue en titre sur un arrêté : le décret cité n'est pas l'acte.
    expect($this->extracteur->typeContredit('Décret n° 2007-274 du 21 mai 2007 susvisé, il est…', 'ARR'))->toBeTrue()
        ->and($this->extracteur->typeContredit('Arrêté n° 3497 du 2 septembre 2025', 'ARR'))->toBeFalse()
        // « Loi n° … » sur une loi constitutionnelle : abréviation courante, pas une contradiction.
        ->and($this->extracteur->typeContredit('Loi n° 1-2025 du 3 mars 2025', 'CONST'))->toBeFalse()
        // Un type hors de la liste croisable ne se laisse pas contredire.
        ->and($this->extracteur->typeContredit('Décret n° 2025-279 du 2 juillet 2025 (statuts annexés)', 'TEXTE'))->toBeFalse()
        ->and($this->extracteur->typeContredit('Avis n° 343 de l\'Office des changes', 'ARR'))->toBeFalse();
});

it('rétrograde à vérifier une proposition dont le titre contredit type_code ou date_signature', function () {
    // Arrêté dont le titre est une phrase de corps qui cite un décret.
    $fantome = LegalDocument::factory()->create([
        'type_code' => 'ARR',
        'titre_officiel' => 'Décret n° 2007-274 du 21 mai 2007 susvisé, il est attribué…',
        'date_signature' => '2025-09-03',
        'curation_status' => 'published',
    ]);
    // Titre fidèle mais date de signature enregistrée différente.
    $dateFausse = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret n° 2025-240 du 20 juin 2025.',
        'date_signature' => '2025-06-21',
        'curation_status' => 'published',
    ]);
    // Cohérent : reste de confiance haute.
    $sain = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret n° 2025-241 du 20 juin 2025.',
        'date_signature' => '2025-06-20',
        'curation_status' => 'published',
    ]);

    $sortie = tempnam(sys_get_temp_dir(), 'numeros').'.json';

    $this->artisan('mibeko:proposer-numeros', ['--connection' => 'pgsql', '--out' => $sortie])->assertSuccessful();

    $propositions = collect(json_decode((string) file_get_contents($sortie), true))->keyBy('id');

    expect($propositions[$fantome->id]['confiance'])->toBe('a_verifier')
        ->and($propositions[$fantome->id]['alertes'])->toContain('type_incoherent')
        ->and($propositions[$dateFausse->id]['confiance'])->toBe('a_verifier')
        ->and($propositions[$dateFausse->id]['alertes'])->toBe(['date_incoherente'])
        ->and($propositions[$dateFausse->id]['date_titre'])->toBe('2025-06-20')
        ->and($propositions[$sain->id]['confiance'])->toBe('haute')
        ->and($propositions[$sain->id]['alertes'])->toBe([]);

    unlink($sortie);
});

// ── Le canal proposer → relecture → appliquer ────────────────────────────────

it('propose des numéros sans rien écrire en base', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret n° 2025-340 du 7 août 2025.',
        'date_signature' => '2025-08-07',
        'curation_status' => 'published',
    ]);

    $sortie = tempnam(sys_get_temp_dir(), 'numeros').'.json';

    $this->artisan('mibeko:proposer-numeros', [
        '--connection' => 'pgsql',
        '--statut' => 'published',
        '--out' => $sortie,
    ])->assertSuccessful();

    $propositions = json_decode((string) file_get_contents($sortie), true);

    expect($propositions)->toHaveCount(1)
        ->and($propositions[0]['id'])->toBe($document->id)
        ->and($propositions[0]['numero'])->toBe('2025-340')
        ->and($propositions[0]['confiance'])->toBe('haute')
        ->and($propositions[0]['citation_partagee'])->toBeFalse();

    // Le contrat de la commande : elle PROPOSE, elle n'écrit pas.
    expect($document->fresh()->numero_acte)->toBeNull()
        ->and($document->fresh()->titre_officiel)->toBe('Décret n° 2025-340 du 7 août 2025.');

    unlink($sortie);
});

it('marque les propositions qui donneraient la même citation à deux textes', function () {
    foreach (['Décret n° 2025-346 du 3 juillet 2025.', 'Décret n° 2025-346 du 3 juillet 2025'] as $titre) {
        LegalDocument::factory()->create([
            'titre_officiel' => $titre,
            'type_code' => 'DEC',
            'date_signature' => '2025-07-03',
            'curation_status' => 'published',
        ]);
    }

    $sortie = tempnam(sys_get_temp_dir(), 'numeros').'.json';

    $this->artisan('mibeko:proposer-numeros', [
        '--connection' => 'pgsql',
        '--statut' => 'published',
        '--out' => $sortie,
    ])->assertSuccessful();

    $propositions = json_decode((string) file_get_contents($sortie), true);

    expect($propositions)->toHaveCount(2)
        ->and(collect($propositions)->pluck('citation_partagee')->all())->toBe([true, true]);

    unlink($sortie);
});

it('refuse un lot qui porterait un titre au lieu d\'un numéro', function () {
    Http::fake();

    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    file_put_contents($lot, json_encode([
        ['id' => (string) Str::uuid(), 'titre' => 'Décret n° 2025-240 du 20 juin 2025'],
    ]));

    $this->artisan('mibeko:appliquer-numeros', ['--liste' => $lot, '--execute' => true])
        ->assertFailed();

    Http::assertNothingSent();

    unlink($lot);
});

it('refuse en bloc un lot dont un numéro est mal formé', function () {
    Http::fake();

    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    file_put_contents($lot, json_encode([
        ['id' => (string) Str::uuid(), 'numero' => '2025-240'],
        ['id' => (string) Str::uuid(), 'numero' => 'CAB-3497'],
    ]));

    // Rien n'est écrit, pas même l'entrée valide : un fichier relu qui porte
    // une coquille se corrige, il ne s'applique pas à moitié.
    $this->artisan('mibeko:appliquer-numeros', ['--liste' => $lot, '--execute' => true])
        ->assertFailed();

    Http::assertNothingSent();

    unlink($lot);
});

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    file_put_contents($lot, json_encode([
        ['id' => (string) Str::uuid(), 'numero' => '2025-240', 'confiance' => 'haute'],
    ]));

    $this->artisan('mibeko:appliquer-numeros', ['--liste' => $lot])->assertSuccessful();

    Http::assertNothingSent();

    unlink($lot);
});

it('écrit un fichier de retour arrière rejouable avant la première écriture', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);
    putenv('MIBEKO_API_TOKEN=jeton-de-test');

    $id = (string) Str::uuid();
    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';
    $retour = tempnam(sys_get_temp_dir(), 'retour').'.json';

    file_put_contents($lot, json_encode([
        ['id' => $id, 'numero' => '2025-240', 'numero_actuel' => null, 'titre_officiel' => 'Décret n° 2025-240'],
    ]));

    $this->artisan('mibeko:appliquer-numeros', [
        '--liste' => $lot,
        '--retour-arriere' => $retour,
        '--rythme' => 0,
        '--execute' => true,
    ])->assertSuccessful();

    $inverse = json_decode((string) file_get_contents($retour), true);

    // Le retour arrière porte l'état d'AVANT — ici « pas de numéro » — et se
    // rejoue tel quel : un `numero` nul est un retrait, pas une ligne à
    // ignorer.
    expect($inverse)->toHaveCount(1)
        ->and($inverse[0]['id'])->toBe($id)
        ->and($inverse[0]['numero'])->toBeNull();

    Http::assertSent(fn ($requete) => $requete['numero_acte'] === '2025-240'
        && $requete['numero_acte_source'] === 'titre');

    putenv('MIBEKO_API_TOKEN');
    unlink($lot);
    unlink($retour);
});

// ── Le détecteur de citations ────────────────────────────────────────────────

it('signale les citations partagées et les références introuvables sans rien écrire', function () {
    $jumeaux = collect(['Arrêté n° 3830 du 4 septembre 2025.', 'Arrêté n° 3830 du 4 septembre 2025'])
        ->map(fn (string $titre) => LegalDocument::factory()->create([
            'titre_officiel' => $titre,
            'type_code' => 'ARR',
            'numero_acte' => '3830',
            'numero_acte_source' => 'titre',
            'date_signature' => '2025-09-04',
            'curation_status' => 'published',
        ]));

    $orphelin = LegalDocument::factory()->create([
        'titre_officiel' => 'Avis de recrutement dans la fonction publique',
        'curation_status' => 'published',
    ]);

    $rapport = tempnam(sys_get_temp_dir(), 'citations').'.json';
    $lot = tempnam(sys_get_temp_dir(), 'lot').'.json';

    $this->artisan('mibeko:detecter-citations', [
        '--connection' => 'pgsql',
        '--statut' => 'published',
        '--json' => $rapport,
        '--lot' => $lot,
    ])->assertSuccessful();

    $mesure = json_decode((string) file_get_contents($rapport), true);

    expect($mesure['doublons_citation'])->toHaveCount(1)
        ->and($mesure['doublons_citation'][0])->toHaveCount(2)
        ->and(collect($mesure['reference_introuvable'])->pluck('id')->all())->toBe([$orphelin->id]);

    // Le détecteur ne crée aucun signalement : il prépare un lot que l'humain
    // valide au clavier (production.md § 6 bis).
    expect(DB::table('curation_flags')->count())->toBe(0);

    $prepare = json_decode((string) file_get_contents($lot), true);

    expect($prepare['operation'])->toBe('creer_signalements')
        ->and($prepare['expected_rows'])->toBe(3)
        ->and($prepare['params']['signalements'])->toHaveCount(3)
        ->and(collect($prepare['params']['signalements'])->pluck('type_probleme')->unique()->sort()->values()->all())
        ->toBe([DetecterCitationsCommand::TYPE_DOUBLON_CITATION, DetecterCitationsCommand::TYPE_REFERENCE_INTROUVABLE]);

    // Le lot doit passer la liste blanche Classe 1 telle quelle, sinon la file
    // le refusera dans le terminal de l'humain — trop tard pour le corriger.
    expect(app(OperationsClasseUne::class)->violations($prepare, $this->editor))->toBe([]);

    expect($jumeaux)->toHaveCount(2);

    unlink($rapport);
    unlink($lot);
});
