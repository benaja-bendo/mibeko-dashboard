<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Services\EmptyStructureNodeRemover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `mibeko:retirer-noeuds-vides` (dashboard#221) : retire d'un texte les divisions
 * sans aucun article dans leur sous-arbre, par suppression logique. Reproduit le
 * JO n° 1-2011 spécial tel qu'il est en production : un sommaire à points de
 * conduite lu comme structure, à côté de la vraie structure.
 */

/**
 * @return array{document: LegalDocument, vides: list<string>, gardes: list<string>, livre9: string, remontes: list<string>, articles: array<string, string>}
 */
function documentAvecSommaire(): array
{
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Journal officiel n° 1-2011 spécial',
        'curation_status' => 'published',
        'document_role' => 'FLUX',
    ]);

    // clé => [parent, type, numéro, titre, sort_order, article porté]
    $divisions = [
        // Sommaire : tout est vide, y compris le Livre V dont le titre est coupé (pas de points).
        'sL1' => [null, 'LIVRE', 'I', 'STATUT DU COMMERÇANT ET DE L\'ENTREPRENANT . . . . . . . . . 4', 2, null],
        'sT1' => ['sL1', 'TITRE', 'I', 'Statut du commerçant . . . . . . . . . . . . . 4', 3, null],
        'sC1' => ['sT1', 'CHAPITRE', 'I', 'Définition du commerçant . . . . . . . . . . 4', 4, null],
        'sL5' => [null, 'LIVRE', 'V', 'INFORMATISATION DU REGISTRE DU COMMERCE ET DU CRÉDIT MOBILIER, DU FICHIER NATIONAL ET DU', 33, null],
        'sC5' => ['sL5', 'CHAPITRE', 'I', 'Dispositions générales . . . . . . . . . . 36', 34, null],
        // Vraie structure : L1 et T1 n'ont aucun article direct, mais un descendant en porte un.
        'L1' => [null, 'LIVRE', 'I', 'STATUT DU COMMERÇANT ET DE', 90, null],
        'T1' => ['L1', 'TITRE', 'I', 'Statut du commerçant', 91, null],
        'C1' => ['T1', 'CHAPITRE', 'I', 'Définition du commerçant et des actes de commerce', 92, '2'],
        'L2' => [null, 'LIVRE', 'II', 'REGISTRE DU COMMERCE', 132, '34'],
        // Vraie division dont le titre contient des points : elle porte un article, elle reste.
        'D' => [null, 'LIVRE', 'III', 'Définitions . . . . et abréviations', 187, '60'],
        // Le piège : la dernière ligne du sommaire a reçu en fils la vraie première division du texte.
        'sL9' => [null, 'LIVRE', 'IX', 'DISPOSITIONS TRANSITOIRES ET FINALES . . . . . . 77', 87, null],
        'P' => ['sL9', 'CHAPITRE', 'PRELIMINAIRE', 'CHAMP D\'APPLICATION', 88, '1'],
        'P1' => ['P', 'SECTION', '1', 'Sous-section', 89, '1bis'],
    ];

    $ids = array_map(fn () => (string) Str::uuid(), $divisions);
    $path = function (string $cle) use (&$path, $divisions, $ids): string {
        $label = 'n_'.str_replace('-', '_', $ids[$cle]);

        return $divisions[$cle][0] === null ? $label : $path($divisions[$cle][0]).'.'.$label;
    };

    $articles = [];
    foreach ($divisions as $cle => [$parent, $type, $numero, $titre, $sortOrder, $article]) {
        StructureNode::factory()->create([
            'id' => $ids[$cle],
            'document_id' => $document->id,
            'type_unite' => $type,
            'numero' => $numero,
            'titre' => $titre,
            'tree_path' => $path($cle),
            'sort_order' => $sortOrder,
        ]);
        if ($article !== null) {
            $articles[$article] = Article::factory()->create([
                'document_id' => $document->id,
                'parent_node_id' => $ids[$cle],
                'numero_article' => $article,
            ])->id;
        }
    }

    return [
        'document' => $document,
        'vides' => [$ids['sL1'], $ids['sT1'], $ids['sC1'], $ids['sL5'], $ids['sC5']],
        'gardes' => [$ids['L1'], $ids['T1'], $ids['C1'], $ids['L2'], $ids['D']],
        'livre9' => $ids['sL9'],
        'remontes' => [$ids['P'], $ids['P1']],
        'articles' => $articles,
    ];
}

function empreinteAnnoncee(LegalDocument $document, array $dissoudre = []): string
{
    return app(EmptyStructureNodeRemover::class)->plan(DB::connection(), $document->id, $dissoudre)['fingerprint'];
}

function label(string $id): string
{
    return 'n_'.str_replace('-', '_', $id);
}

/** Nœuds vivants dont le parent, dans le chemin, n'existe pas parmi les nœuds vivants. */
function nœudsSansParent(LegalDocument $document): int
{
    return (int) DB::selectOne('select count(*) as n from structure_nodes n where n.document_id = ? and n.deleted_at is null and nlevel(n.tree_path) > 1
        and not exists (select 1 from structure_nodes p where p.document_id = n.document_id and p.deleted_at is null and p.tree_path = subpath(n.tree_path, 0, -1))', [$document->id])->n;
}

/** @return array<string, mixed> */
function executer(LegalDocument $document, array $surcharge = [], array $dissoudre = [], int $attendu = 5): array
{
    return array_merge([
        '--document' => $document->id,
        '--connection' => 'pgsql',
        '--execute' => true,
        '--attendu' => $attendu,
        '--empreinte' => empreinteAnnoncee($document, $dissoudre),
        '--revert-file' => storage_path('app/retour-noeuds-vides-test.json'),
    ], $dissoudre === [] ? [] : ['--dissoudre' => $dissoudre], $surcharge);
}

afterEach(function () {
    @unlink(storage_path('app/retour-noeuds-vides-test.json'));
});

it("simule : annonce le nombre et l'empreinte, sans rien écrire", function () {
    ['document' => $document] = documentAvecSommaire();
    $avant = $document->fresh()->updated_at;

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql'])
        ->expectsOutputToContain('5 nœud(s) seraient retirés')
        ->expectsOutputToContain('SIMULATION')
        ->expectsOutputToContain(empreinteAnnoncee($document))
        ->assertSuccessful();

    expect(StructureNode::onlyTrashed()->count())->toBe(0)
        ->and(StructureNode::count())->toBe(13)
        ->and($document->fresh()->updated_at->equalTo($avant))->toBeTrue();
});

it('retire exactement les divisions sans article dans leur sous-arbre, ligne de sommaire sans points comprise', function () {
    ['document' => $document, 'vides' => $vides, 'gardes' => $gardes, 'livre9' => $livre9, 'remontes' => $remontes, 'articles' => $articles] = documentAvecSommaire();

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document))
        ->expectsOutputToContain('5 nœud(s) retiré(s)')
        ->assertSuccessful();

    // Sans --dissoudre, le Livre IX à points reste, avec son fils : rien n'est retiré qui porte un article.
    expect(StructureNode::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe(collect($vides)->sort()->values()->all())
        ->and(StructureNode::pluck('id')->sort()->values()->all())->toBe(collect([...$gardes, $livre9, ...$remontes])->sort()->values()->all());
    // L1 et T1 (aucun article direct) et D (titre à points) restent : ce sont de vraies divisions.
    foreach ($articles as $numero => $id) {
        $article = Article::find($id);
        expect($article)->not->toBeNull()
            ->and(StructureNode::find($article->parent_node_id))->not->toBeNull();
    }
    expect(Article::where('document_id', $document->id)->count())->toBe(5);
});

it('touche le document : son updated_at, qui entre dans version_hash, change', function () {
    ['document' => $document] = documentAvecSommaire();
    DB::table('legal_documents')->where('id', $document->id)->update(['updated_at' => now()->subDay()]);
    $avant = $document->fresh()->updated_at;

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document))->assertSuccessful();

    expect($document->fresh()->updated_at->greaterThan($avant))->toBeTrue();
});

it("écrit l'instantané de retour arrière avant d'écrire, avec l'état complet des nœuds", function () {
    ['document' => $document, 'vides' => $vides] = documentAvecSommaire();
    $fichier = storage_path('app/retour-noeuds-vides-test.json');

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document))->assertSuccessful();

    $snapshot = json_decode(file_get_contents($fichier), true);
    expect($snapshot['document_id'])->toBe($document->id)
        ->and(collect($snapshot['nodes'])->pluck('id')->sort()->values()->all())->toBe(collect($vides)->sort()->values()->all())
        ->and($snapshot['nodes'][0])->toHaveKeys(['id', 'type_unite', 'numero', 'titre', 'tree_path', 'sort_order']);
});

it('refuse un nombre ou une empreinte qui ne sont pas ceux de la simulation, et ne touche à rien', function () {
    ['document' => $document] = documentAvecSommaire();

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, ['--attendu' => 4]))
        ->expectsOutputToContain('Écart')
        ->assertFailed();

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, ['--empreinte' => hash('sha256', 'autre')]))
        ->expectsOutputToContain('empreinte a changé')
        ->assertFailed();

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});

it("refuse d'exécuter sans le nombre et l'empreinte annoncés, ou sur une connexion en lecture seule", function () {
    ['document' => $document] = documentAvecSommaire();

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql', '--execute' => true])
        ->expectsOutputToContain('exige --attendu et --empreinte')
        ->assertFailed();

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, ['--connection' => 'pgsql_prod_ro']))
        ->expectsOutputToContain('connexion en écriture')
        ->assertFailed();

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});

it('refuse quand un signalement non résolu est attaché à un nœud à retirer', function () {
    ['document' => $document, 'vides' => $vides] = documentAvecSommaire();
    CurationFlag::create([
        'document_id' => $document->id,
        'node_id' => $vides[0],
        'source' => 'conformite',
        'severity' => 'blocking',
        'type_probleme' => 'test',
        'description' => 'signalement posé sur une ligne de sommaire',
        'resolved' => false,
    ]);

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql'])
        ->expectsOutputToContain('signalement(s) non résolu(s)')
        ->assertFailed();

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});

it("refuse un document dont tous les nœuds sont vides : ce n'est pas un nettoyage", function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    StructureNode::factory()->create(['document_id' => $document->id, 'tree_path' => 'n_a', 'titre' => 'Livre I . . . . 4', 'sort_order' => 1]);

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql'])
        ->expectsOutputToContain('reconstruction')
        ->assertFailed();

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});

it("dit qu'il n'y a rien à retirer quand tous les nœuds portent un article, et refuse un document inconnu", function () {
    $document = LegalDocument::factory()->create();
    $noeud = StructureNode::factory()->create(['document_id' => $document->id, 'tree_path' => 'n_a', 'sort_order' => 1]);
    Article::factory()->create(['document_id' => $document->id, 'parent_node_id' => $noeud->id]);

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql'])
        ->expectsOutputToContain('rien à retirer')
        ->assertSuccessful();

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => (string) Str::uuid(), '--connection' => 'pgsql'])
        ->expectsOutputToContain('introuvable')
        ->assertFailed();
});

it('ignore les nœuds déjà supprimés logiquement et ne touche pas à leur date', function () {
    ['document' => $document, 'vides' => $vides] = documentAvecSommaire();
    $dejaRetire = StructureNode::factory()->create([
        'document_id' => $document->id, 'tree_path' => 'n_ancien', 'sort_order' => 500, 'deleted_at' => now()->subMonth(),
    ]);
    $date = $dejaRetire->fresh()->deleted_at;

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document))->assertSuccessful();

    expect(StructureNode::onlyTrashed()->count())->toBe(6)
        ->and(StructureNode::withTrashed()->find($dejaRetire->id)->deleted_at->equalTo($date))->toBeTrue();
});

it("rétablit exactement les nœuds retirés à partir de l'instantané", function () {
    ['document' => $document, 'vides' => $vides] = documentAvecSommaire();
    $this->artisan('mibeko:retirer-noeuds-vides', executer($document))->assertSuccessful();
    $fichier = storage_path('app/retour-noeuds-vides-test.json');

    $this->artisan('mibeko:retirer-noeuds-vides', ['--restaurer' => $fichier, '--connection' => 'pgsql'])
        ->expectsOutputToContain('SIMULATION')
        ->assertSuccessful();
    expect(StructureNode::onlyTrashed()->count())->toBe(5);

    $this->artisan('mibeko:retirer-noeuds-vides', ['--restaurer' => $fichier, '--connection' => 'pgsql', '--execute' => true])
        ->expectsOutputToContain('5 nœud(s) rétabli(s)')
        ->assertSuccessful();

    expect(StructureNode::onlyTrashed()->count())->toBe(0)
        ->and(StructureNode::count())->toBe(13)
        ->and(empreinteAnnoncee($document))->toBe(json_decode(file_get_contents($fichier), true)['fingerprint']);

    // Rejouer l'instantané quand rien n'est supprimé logiquement : refus, rien n'est écrit.
    $this->artisan('mibeko:retirer-noeuds-vides', ['--restaurer' => $fichier, '--connection' => 'pgsql', '--execute' => true])
        ->expectsOutputToContain('supprimés logiquement')
        ->assertFailed();
});

it('dissout une ligne de sommaire qui a reçu la vraie première division : les descendants remontent d\'un niveau', function () {
    ['document' => $document, 'vides' => $vides, 'livre9' => $livre9, 'remontes' => [$prelim, $sous], 'articles' => $articles] = documentAvecSommaire();

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, dissoudre: [$livre9], attendu: 6))
        ->expectsOutputToContain('6 nœud(s) retiré(s), 2 remonté(s)')
        ->assertSuccessful();

    expect(StructureNode::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe(collect([...$vides, $livre9])->sort()->values()->all())
        ->and(StructureNode::find($prelim)->tree_path)->toBe(label($prelim))
        ->and(StructureNode::find($sous)->tree_path)->toBe(label($prelim).'.'.label($sous))
        ->and(nœudsSansParent($document))->toBe(0)
        ->and(Article::where('document_id', $document->id)->count())->toBe(5)
        ->and(StructureNode::where('tree_path', 'like', '%'.label($livre9).'%')->count())->toBe(0);
    foreach ($articles as $id) {
        expect(StructureNode::find(Article::find($id)->parent_node_id))->not->toBeNull();
    }
});

it("annonce les nœuds remontés en simulation, et l'empreinte change avec la dissolution", function () {
    ['document' => $document, 'livre9' => $livre9] = documentAvecSommaire();

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql', '--dissoudre' => [$livre9]])
        ->expectsOutputToContain('6 nœud(s) seraient retirés')
        ->expectsOutputToContain('Remonte')
        ->expectsOutputToContain('--dissoudre='.$livre9)
        ->assertSuccessful();

    expect(empreinteAnnoncee($document, [$livre9]))->not->toBe(empreinteAnnoncee($document))
        ->and(StructureNode::onlyTrashed()->count())->toBe(0);
});

it('refuse de dissoudre un nœud qui porte un article, un nœud déjà vide, un nœud inconnu ou deux nœuds imbriqués', function () {
    ['document' => $document, 'vides' => $vides, 'gardes' => [$l1, $t1, , $l2]] = documentAvecSommaire();

    foreach ([
        [[$l2], 'porte un article'],
        [[$vides[0]], 'déjà retiré comme nœud vide'],
        [[(string) Str::uuid()], 'introuvable'],
        [[$l1, $t1], 'imbriqués'],
    ] as [$dissoudre, $message]) {
        $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql', '--dissoudre' => $dissoudre])
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});

it('refuse de dissoudre un nœud sur lequel un signalement est ouvert', function () {
    ['document' => $document, 'livre9' => $livre9] = documentAvecSommaire();
    CurationFlag::create([
        'document_id' => $document->id, 'node_id' => $livre9, 'source' => 'conformite', 'severity' => 'blocking',
        'type_probleme' => 'test', 'description' => 'signalement posé sur le Livre IX', 'resolved' => false,
    ]);

    $this->artisan('mibeko:retirer-noeuds-vides', ['--document' => $document->id, '--connection' => 'pgsql', '--dissoudre' => [$livre9]])
        ->expectsOutputToContain('signalement non résolu')
        ->assertFailed();
});

it('rétablit aussi le chemin des nœuds remontés, et refuse si ce chemin a changé depuis', function () {
    ['document' => $document, 'livre9' => $livre9, 'remontes' => [$prelim, $sous]] = documentAvecSommaire();
    $avant = StructureNode::pluck('tree_path', 'id')->all();
    $fichier = storage_path('app/retour-noeuds-vides-test.json');
    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, dissoudre: [$livre9], attendu: 6))->assertSuccessful();

    // Un chemin modifié à la main depuis l'opération : le retour arrière s'arrête, rien n'est écrit.
    DB::update("update structure_nodes set tree_path = 'n_ailleurs'::ltree where id = ?", [$sous]);
    $this->artisan('mibeko:retirer-noeuds-vides', ['--restaurer' => $fichier, '--connection' => 'pgsql', '--execute' => true])
        ->expectsOutputToContain('plus le chemin')
        ->assertFailed();
    expect(StructureNode::onlyTrashed()->count())->toBe(6);

    DB::update('update structure_nodes set tree_path = ?::ltree where id = ?', [label($prelim).'.'.label($sous), $sous]);
    $this->artisan('mibeko:retirer-noeuds-vides', ['--restaurer' => $fichier, '--connection' => 'pgsql', '--execute' => true])
        ->expectsOutputToContain('6 nœud(s) rétabli(s)')
        ->assertSuccessful();

    expect(StructureNode::onlyTrashed()->count())->toBe(0)
        ->and(StructureNode::pluck('tree_path', 'id')->all())->toEqual($avant)
        ->and(nœudsSansParent($document))->toBe(0);
});

it("refuse si l'arbre sous le nœud à dissoudre a changé depuis la simulation", function () {
    ['document' => $document, 'livre9' => $livre9] = documentAvecSommaire();
    $empreinte = empreinteAnnoncee($document, [$livre9]);

    // Un nouveau descendant apparaît : mêmes nœuds retirés, mais l'ensemble des nœuds remontés n'est plus le même.
    $nouveau = StructureNode::factory()->create([
        'document_id' => $document->id, 'tree_path' => StructureNode::find($livre9)->tree_path.'.n_nouveau', 'sort_order' => 95,
    ]);
    Article::factory()->create(['document_id' => $document->id, 'parent_node_id' => $nouveau->id]);

    $this->artisan('mibeko:retirer-noeuds-vides', executer($document, ['--empreinte' => $empreinte], [$livre9], 6))
        ->expectsOutputToContain('empreinte a changé')
        ->assertFailed();

    expect(StructureNode::onlyTrashed()->count())->toBe(0);
});
