<?php

use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Services\Curation\SlugFromCitationGenerator;

/**
 * Dérivation du slug canonique depuis la citation (dashboard#156). Le
 * générateur ne compare jamais au slug actuel : c'est un pur calcul, à
 * `mibeko:regenerer-slugs` de décider quoi en faire.
 */
beforeEach(function () {
    foreach (['DEC', 'ARR', 'LOI', 'ORD', 'CONST', 'TEXTE', 'AU', 'CODE'] as $code) {
        DocumentType::firstOrCreate(['code' => $code], ['nom' => $code]);
    }
    $this->generateur = new SlugFromCitationGenerator;
});

function documentAvecCitation(string $type, string $numero, string $dateSignature): LegalDocument
{
    return LegalDocument::factory()->create([
        'type_code' => $type,
        'numero_acte' => $numero,
        'numero_acte_source' => 'titre',
        'date_signature' => $dateSignature,
    ]);
}

it('dérive le slug d\'un décret ordinaire', function () {
    $document = documentAvecCitation('DEC', '2025-240', '2025-06-20');

    expect($this->generateur->candidat($document))->toBe('decret-n-2025-240-du-20-juin-2025');
});

it('dérive le slug d\'un arrêté, avec le 1er du mois écrit "1er"', function () {
    $document = documentAvecCitation('ARR', '3497', '2025-09-01');

    expect($this->generateur->candidat($document))->toBe('arrete-n-3497-du-1er-septembre-2025');
});

it('traverse les 12 mois sans accroc (translittération Str::slug)', function () {
    foreach ([1 => 'janvier', 2 => 'fevrier', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'aout', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'decembre'] as $mois => $attendu) {
        $document = documentAvecCitation('DEC', '1', sprintf('2020-%02d-15', $mois));
        expect($this->generateur->candidat($document))->toBe("decret-n-1-du-15-{$attendu}-2020");
    }
});

it('dérive le slug d\'une loi constitutionnelle numérotée', function () {
    $document = documentAvecCitation('CONST', '1', '2025-03-03');

    expect($this->generateur->candidat($document))->toBe('loi-constitutionnelle-n-1-du-3-mars-2025');
});

it('normalise un numéro avec code de service — le / disparaît sans séparateur, comme partout ailleurs dans le corpus (Str::slug)', function () {
    $document = documentAvecCitation('DEC', '80-550/ETR-SGDAAPDP', '1980-01-10');

    expect($this->generateur->candidat($document))->toBe('decret-n-80-550etr-sgdaapdp-du-10-janvier-1980');
});

it('ne propose rien pour un type hors de la liste (TEXTE, AU, CODE, sans type)', function () {
    foreach (['TEXTE', 'AU', 'CODE'] as $type) {
        $document = documentAvecCitation($type, '1', '2020-01-01');
        expect($this->generateur->candidat($document))->toBeNull();
    }

    $sansType = documentAvecCitation('DEC', '1', '2020-01-01');
    $sansType->update(['type_code' => null]);

    expect($this->generateur->candidat($sansType->refresh()))->toBeNull();
});

it('ne propose rien sans numéro d\'acte, ni sans date de signature', function () {
    $sansNumero = LegalDocument::factory()->create(['type_code' => 'DEC', 'numero_acte' => null, 'date_signature' => '2020-01-01']);
    $sansDate = documentAvecCitation('DEC', '2020-1', '2020-01-01');
    $sansDate->update(['date_signature' => null]);
    $sansDate->refresh();

    expect($this->generateur->candidat($sansNumero))->toBeNull()
        ->and($this->generateur->candidat($sansDate))->toBeNull();
});

it('ne propose rien pour une constitution NON numérotée (type CONST sans numéro)', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CONST',
        'titre_officiel' => 'Constitution de la République du Congo de 2015',
        'numero_acte' => null,
    ]);

    expect($this->generateur->candidat($document))->toBeNull();
});
