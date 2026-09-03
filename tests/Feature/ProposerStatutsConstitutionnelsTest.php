<?php

use App\Models\LegalDocument;

/**
 * Les 26 titres exacts utilisés par la commande, exactement comme dans
 * `ProposerStatutsConstitutionnelsCommand::STATUTS`/`RELATIONS` — sert aussi
 * de garde-fou contre une faute de frappe silencieuse dans le mapping réel :
 * si un titre change ici sans changer là-bas (ou l'inverse), la résolution
 * échoue et le test le montre.
 */
function seederTousLesTextesConstitutionnels(): void
{
    $titres = [
        "Loi constitutionnelle n° 3 du 16 février 1959, suspendant provisoirement l'application de l'article 2 de la loi constitutionnelle n° 1 du 28 novembre 1958",
        "Loi constitutionnelle n° 4 du 20 février 1959 relative à l'Assemblée législative",
        'LOI CONSTITUTIONNELLE N° 5 DU 20 FEVRIER 1959 RELATIVE AU GOVERNEMENT DE LA REPUBLICQUE',
        'Loi constitutionnelle n° 9 du 3 novembre 1959 relative à la devise de la République du Congo.',
        'Loi constitutionnelle n° 11 du 21 novembre 1959 relative à la présidence de la République',
        'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo',
        'Constitution de la République du Congo du 8 décembre 1963',
        'Constitution de la République Populaire du Congo du 30 décembre 1969',
        'Ordonnance n° 40-69 du 31 décembre 1969, portant promulgation de la constitution de la République Populaire du Congo',
        'Acte fondamental de la République du Congo du 4 juin 1991',
        'Constitution de la République du Congo du 15 mars 1992',
        'Constitution de la République du Congo du 20 janvier 2002',
        'ORDONNANCE No 019-84 du 23 août 1984, portant modification de certaines dispositions de la Constitution du 8 juillet 1979.',
        "LOI N° 076-84 du 7 décembre 1984, portant ratification de l'Ordonnance no 019-84 du 23 ao ut 1984, portant modification de Certaines dispositions de la Constitution du 8 juillet 1979.",
        'LOI CONSTITUTIONNELLE NUMERO 1',
        'LOI CONSTITUTIONNELLE NUMERO 2',
        'LOI CONSTITUTIONNELLE N° 6 DU 20 FEVRIER 1959 RELATIVE AUX RAPPORTS ENTRE LES POUVOIRS PUBLICS',
        'LOI CONSTITUTIONNELLE N° 7 DU 20 FÉVRIER 1959 RELATIVE A LA MISE EN PLACE DES INSTITUTIONS',
        'Loi constitutionnelle n° 8 du 18 août 1959, fixant le drapeau de la République du Congo',
        "Loi constitutionnelle n° 10 du 21 novembre 1959 relative à l'émme national de la République du Congo",
        "Loi constitutionnelle n° 12 du 7 décembre 1959 relative au titre de l'Assemblée législative de la République du Congo.",
        'Constitution de la République Populaire du Congo du 24 juin 1973',
        'Acte fondamental de la République du Congo du 24 octobre 1997',
        'Republique du Congo Constitution 2015',
    ];

    foreach ($titres as $titre) {
        LegalDocument::factory()->create([
            'titre_officiel' => $titre,
            'curation_status' => 'published',
            'statut' => 'vigueur',
        ]);
    }
}

it('résout les 14 statuts et 21 relations sur le jeu complet', function () {
    seederTousLesTextesConstitutionnels();

    $cheminStatuts = storage_path('app/test-statuts.json');
    $cheminRelations = storage_path('app/test-relations.json');

    $this->artisan('mibeko:proposer-statuts-constitutionnels', [
        '--connection' => 'pgsql',
        '--out-statuts' => $cheminStatuts,
        '--out-relations' => $cheminRelations,
    ])->assertSuccessful();

    $statuts = json_decode(file_get_contents($cheminStatuts), true);
    $relations = json_decode(file_get_contents($cheminRelations), true);

    expect($statuts)->toHaveCount(14);
    expect($relations)->toHaveCount(21);
    expect(collect($statuts)->pluck('statut')->unique()->all())->toBe(['abroge']);
    expect(collect($statuts)->pluck('id'))->each->not->toBeEmpty();
    expect(collect($relations)->pluck('source_doc_id'))->each->not->toBeEmpty();
    expect(collect($relations)->pluck('target_doc_id'))->each->not->toBeEmpty();

    @unlink($cheminStatuts);
    @unlink($cheminRelations);
});

it('échoue bruyamment si un titre ne trouve aucune correspondance', function () {
    // Aucun document seedé : chaque titre attendu a 0 correspondance.
    $this->artisan('mibeko:proposer-statuts-constitutionnels', ['--connection' => 'pgsql'])
        ->assertFailed();
});

it('échoue bruyamment si un titre est ambigu', function () {
    seederTousLesTextesConstitutionnels();

    // Un doublon du même titre exact rend la résolution ambiguë.
    LegalDocument::factory()->create([
        'titre_officiel' => 'Constitution de la République du Congo du 8 décembre 1963',
        'curation_status' => 'published',
        'statut' => 'vigueur',
    ]);

    $this->artisan('mibeko:proposer-statuts-constitutionnels', ['--connection' => 'pgsql'])
        ->assertFailed();
});
