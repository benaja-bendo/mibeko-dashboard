<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cas réels prélevés en production le 03/08/2026, avant construction de la
 * commande, pour valider la règle de reconstruction sur de vraies données —
 * pas des exemples inventés.
 */
uses(RefreshDatabase::class);

function documentAvecPreambule(string $titre, string $preambule): LegalDocument
{
    $document = LegalDocument::factory()->create(['titre_officiel' => $titre, 'curation_status' => 'draft']);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'PREAMBULE']);
    ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => $preambule]);

    return $document;
}

it('recolle une césure simple avec la formule de qualité du signataire juste après', function () {
    // Cas réel : Arrêté n° 1568.
    $document = documentAvecPreambule(
        'Arrêté n° 1568 du 18 juin 2025 portant ad-',
        "jonction de nom de Mlle NDINGA (Vanessa Grâce)\nLe garde des sceaux, ministre de la justice,\nVu la Constitution ;",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true))
        ->toBe([['id' => $document->id, 'titre' => 'Arrêté n° 1568 du 18 juin 2025 portant adjonction de nom de Mlle NDINGA (Vanessa Grâce)']]);
});

it('recolle une césure dont la formule de qualité s\'étend sur plusieurs lignes', function () {
    // Cas réel : Arrêté n° 1670 — la formule de qualité du ministre est sur 2 lignes.
    $document = documentAvecPreambule(
        'Arrêté n° 1670 du 20 juin 2025 portant agré-',
        "ment de M. (Hicham) FADILI, en qualité de directeur\ngénéral de la banque Crédit du Congo\nLe ministre des finances, du budget\net du portefeuille public,\nVu la Constitution ;",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('Arrêté n° 1670 du 20 juin 2025 portant agrément de M. (Hicham) FADILI, en qualité de directeur général de la banque Crédit du Congo');
});

it('recolle une césure qui réapparaît à l\'intérieur même du préambule', function () {
    // Cas réel : Arrêté minier n° 11429 — « distribu- » puis « tion » sont sur
    // deux lignes du PREAMBULE lui-même, pas seulement à la jonction titre/corps.
    $document = documentAvecPreambule(
        'Arrêté n° 11429 du 15 septembre 2023 por-',
        "tant attribution à la société Mission du cèdre distribu-\ntion d'une autorisation d'ouverture\nLe ministre d'Etat, ministre des industries minières\net de la géologie,\nVu la Constitution ;",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe("Arrêté n° 11429 du 15 septembre 2023 portant attribution à la société Mission du cèdre distribution d'une autorisation d'ouverture");
});

it('nettoie un résidu LaTeX sans avoir besoin de continuation, quand le titre est déjà complet', function () {
    // Cas réel : Décret n° 90-045 — le titre porte déjà tout l'objet, PREAMBULE commence direct par l'autorité.
    $document = documentAvecPreambule(
        'DECRET 90-045 du 27 Férvrier 1990, portant reclassement de Mr BALLOUA-MPIO (Robin Gustave), Instituteur de $5^{\circ}$ échelon.',
        "LE PREMIER MINISTRE,\nVu la Constitution ;",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('DECRET 90-045 du 27 Férvrier 1990, portant reclassement de Mr BALLOUA-MPIO (Robin Gustave), Instituteur de 5° échelon.');
});

it('nettoie \bullet comme un alias mal océrisé de \circ (degré)', function () {
    // Cas réel : DECRET N° 80-489 — le titre n'est qu'une référence, l'objet est dans le PREAMBULE.
    $document = documentAvecPreambule(
        'DECRET $\mathbf{N}^{\bullet}$ 80-489/MTJ.DGTFP.DFP/21025',
        "portant intégration et nomination de M. MATALA DE MAZZA (Romuald Paul Rémy)\nLE PREMIER MINISTRE,\nCHEF DU GOUVERNEMENT,\nVu la constitution du 8 juillet 1979 ;",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('DECRET N° 80-489/MTJ.DGTFP.DFP/21025 portant intégration et nomination de M. MATALA DE MAZZA (Romuald Paul Rémy)');
});

it('ne touche jamais un titre qui se termine déjà par une ponctuation forte, même avec un PREAMBULE atypique', function () {
    // Cas réel régressé avant ce garde-fou : le PREAMBULE d'une loi s'ouvre
    // sur « L'Assemblée … a délibéré et adopté », que la commande ne
    // reconnaît pas comme une formule d'autorité — sans ce garde-fou, elle
    // accolait toute la formule de promulgation à un titre pourtant complet.
    $document = documentAvecPreambule(
        'Loi $\mathbf{n}^{\circ}$ 49-59 du 17 novembre 1959 modifiant et complétant le code des impôts directs du Congo.',
        "L'Assemblée nationale a délibéré et adopté,\nLe Président de la République promulgue la loi dont la teneur suit :",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('Loi n° 49-59 du 17 novembre 1959 modifiant et complétant le code des impôts directs du Congo.');
});

it('retire l\'espace orphelin laissé par un délimiteur LaTeX devant une virgule, sans toucher au « ; »', function () {
    // Cas réel : DECRET N° 80-465 — « $A$ » retiré laisse « A , hierarchie ».
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'DECRET N° 80-465, portant intégration dans la catégorie $A$ , hierarchie I ; Vu la Constitution ;',
        'curation_status' => 'draft',
    ]);

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('DECRET N° 80-465, portant intégration dans la catégorie A, hierarchie I ; Vu la Constitution ;');
});

it('convertit \Lambda en A, une confusion OCR avec la lettre latine, jamais une lettre grecque dans ce corpus', function () {
    // Cas réel : DECRET N° 80-464 — sans ce correctif, la lettre de catégorie disparaissait purement et simplement.
    LegalDocument::factory()->create([
        'titre_officiel' => 'DECRET N° 80-464/MTJ.DGTFP.DFP.21021/8, portant intégration dans la catégorie $\Lambda$, hierarchie I.',
        'curation_status' => 'draft',
    ]);

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe('DECRET N° 80-464/MTJ.DGTFP.DFP.21021/8, portant intégration dans la catégorie A, hierarchie I.');
});

it('classe suspect plutôt que de deviner un résidu LaTeX non interprétable (ex. \text{®} pour un « ° » mal océrisé)', function () {
    // Cas réel : DECRET N ® 80-472 — \text{®} ne doit jamais être proposé tel quel.
    LegalDocument::factory()->create([
        'titre_officiel' => 'DECRET N $^{\text{®}}$ 80-472/MJT/DGTFP-DFP/2.2022/15, portant intégration.',
        'curation_status' => 'draft',
    ]);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('suspect')
        ->and($resultat['raison'])->toContain('caractère isolé');
});

it('arrête la continuation à la formule de promulgation d\'une loi, pas seulement à une qualité de signataire', function () {
    // Cas réel régressé : Loi n° 24-2023 — sans "l'assemblée" dans les
    // formules d'autorité, la promulgation entière s'accolait au titre.
    $document = documentAvecPreambule(
        'Loi n° 24-2023 du 15 septembre 2023 autori-',
        "sant la ratification de l'accord de coopération relatif à l'exemption de visa\nL'Assemblée nationale et le Sénat ont délibéré et adopté ;\nLe Président de la République promulgue la loi dont la teneur suit :",
    );

    $mapping = tempnam(sys_get_temp_dir(), 'mapping_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--mapping' => $mapping])
        ->assertSuccessful();

    expect(json_decode((string) file_get_contents($mapping), true)[0]['titre'])
        ->toBe("Loi n° 24-2023 du 15 septembre 2023 autorisant la ratification de l'accord de coopération relatif à l'exemption de visa");
});

it('classe suspect quand aucun PREAMBULE n\'existe en base', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 999 du 1 janvier 2025 portant agré-',
        'curation_status' => 'draft',
    ]);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('suspect')
        ->and($resultat['raison'])->toContain('aucun PREAMBULE');
});

it('classe suspect quand le résultat se termine encore par une césure', function () {
    // Le PREAMBULE ne contient que la formule d'autorité, sans texte de continuation.
    $document = documentAvecPreambule(
        'Arrêté n° 500 du 1 janvier 2025 portant agré-',
        "Le ministre de la justice,\nVu la Constitution ;",
    );

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    $resultat = json_decode((string) file_get_contents($rapport), true)[0];
    expect($resultat['classe'])->toBe('suspect')
        ->and($resultat['raison'])->toContain('césure');
});

it('ignore les documents dont l\'intitulé ne porte ni numéro ni date', function () {
    // Un fragment (pas un intitulé canonique) ne doit jamais entrer dans le
    // périmètre de reconstruction automatique de titre.
    documentAvecPreambule('loi indique que les candidatures constituent des actes-', 'peu importe le contenu');

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(file_exists($rapport))->toBeFalse();
});

it('ignore les documents publiés ou déjà propres', function () {
    LegalDocument::factory()->create([
        'titre_officiel' => 'Loi n° 28-2016 du 12 octobre 2016 portant code forestier',
        'curation_status' => 'draft',
    ]);
    LegalDocument::factory()->create([
        'titre_officiel' => 'Arrêté n° 1 du 1 janvier 2025 portant agré-',
        'curation_status' => 'published',
    ]);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:reconstruire-titres', ['--connection' => 'pgsql', '--rapport' => $rapport])
        ->assertSuccessful();

    expect(file_exists($rapport))->toBeFalse();
});
