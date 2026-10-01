<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Support\Str;

/**
 * Sommaire servi par l'API pour un texte à plusieurs niveaux (dashboard#217).
 *
 * Reproduit l'Acte uniforme droit commercial général tel qu'il est stocké en
 * production : labels ltree `n_<uuid>` posés par l'ingestion Python, et
 * `sort_order` qui repart à 0 sous chaque parent. `/tree` servait alors des
 * `parent_id` introuvables (`n-<uuid>`) : le lecteur web et l'app mobile
 * rangeaient tout à la racine, triée par un rang qui n'a de sens qu'entre
 * frères (« Art. 169…, puis 135…, puis 30, puis 1 »).
 */

/**
 * Crée le document et ses divisions, dans le désordre, pour que rien ne
 * dépende de l'ordre d'insertion.
 *
 * @return array{document: LegalDocument, ids: array<string, string>}
 */
function codeAPlusieursNiveaux(bool $prefixeIngestion = true): array
{
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Acte uniforme portant droit commercial général']);

    // clé => [parent, type, numéro, titre, sort_order, article porté]
    $divisions = [
        'c4' => ['t4', 'CHAPITRE', '1', 'Définition et champ d\'application', 0, '169'],
        't4' => ['l7', 'TITRE', '1', 'Dispositions communes', 0, null],
        'l7' => [null, 'LIVRE', '7', 'Intermédiaires de commerce', 3, null],
        't3' => ['l2', 'TITRE', '1', 'Dispositions générales', 0, '34'],
        'l2' => [null, 'LIVRE', '2', 'Registre du commerce et du crédit mobilier', 2, null],
        'c3' => ['t2', 'CHAPITRE', '1', 'Définition de l\'entreprenant', 0, '30'],
        'c2' => ['t1', 'CHAPITRE', '2', 'Capacité d\'exercer le commerce', 1, '6'],
        'c1' => ['t1', 'CHAPITRE', '1', 'Définition du commerçant et des actes de commerce', 0, '2'],
        't2' => ['l1', 'TITRE', '2', 'Statut de l\'entreprenant', 1, null],
        't1' => ['l1', 'TITRE', '1', 'Statut du commerçant', 0, null],
        'l1' => [null, 'LIVRE', '1', 'Statut du commerçant et de l\'entreprenant', 1, null],
        'p' => [null, 'CHAPITRE', 'préliminaire', 'Champ d\'application', 0, '1'],
    ];

    $ids = array_map(fn () => (string) Str::uuid(), $divisions);
    $label = fn (string $key): string => ($prefixeIngestion ? 'n_' : '').str_replace('-', '_', $ids[$key]);
    $path = function (string $key) use (&$path, $divisions, $label): string {
        $parent = $divisions[$key][0];

        return $parent === null ? $label($key) : $path($parent).'.'.$label($key);
    };

    foreach ($divisions as $key => [$parent, $type, $numero, $titre, $sortOrder, $article]) {
        StructureNode::factory()->create([
            'id' => $ids[$key],
            'document_id' => $document->id,
            'type_unite' => $type,
            'numero' => $numero,
            'titre' => $titre,
            'tree_path' => $path($key),
            'sort_order' => $sortOrder,
        ]);

        if ($article !== null) {
            Article::factory()->create([
                'document_id' => $document->id,
                'parent_node_id' => $ids[$key],
                'numero_article' => $article,
                'ordre_affichage' => 0,
            ]);
        }
    }

    return ['document' => $document, 'ids' => $ids];
}

/** Titres dans l'ordre officiel du texte : Livre I en tête, emboîté. */
function sommaireOfficiel(): array
{
    return [
        'Champ d\'application',
        'Statut du commerçant et de l\'entreprenant',
        'Statut du commerçant',
        'Définition du commerçant et des actes de commerce',
        'Capacité d\'exercer le commerce',
        'Statut de l\'entreprenant',
        'Définition de l\'entreprenant',
        'Registre du commerce et du crédit mobilier',
        'Dispositions générales',
        'Intermédiaires de commerce',
        'Dispositions communes',
        'Définition et champ d\'application',
    ];
}

it('lit les deux écritures de label ltree', function () {
    $id = '1da663cc-2c07-4a77-be45-10e9f297f281';

    expect(StructureNode::idFromLabel('n_1da663cc_2c07_4a77_be45_10e9f297f281'))->toBe($id)
        ->and(StructureNode::idFromLabel('1da663cc_2c07_4a77_be45_10e9f297f281'))->toBe($id)
        ->and(StructureNode::parentIdFromPath('n_aaa.n_1da663cc_2c07_4a77_be45_10e9f297f281.n_bbb'))->toBe($id)
        ->and(StructureNode::parentIdFromPath('n_1da663cc_2c07_4a77_be45_10e9f297f281'))->toBeNull();
});

it('sert dans /tree des parents qui existent, dans l\'ordre de lecture', function () {
    ['document' => $document, 'ids' => $ids] = codeAPlusieursNiveaux();

    $data = collect($this->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertSuccessful()
        ->json('data'));

    $servedIds = $data->pluck('id')->all();
    $parents = $data->pluck('parent_id')->filter();

    expect($parents)->toHaveCount(8)
        ->and($parents->diff($servedIds))->toBeEmpty()
        ->and($data->firstWhere('id', $ids['c1'])['parent_id'])->toBe($ids['t1'])
        ->and($data->firstWhere('id', $ids['t1'])['parent_id'])->toBe($ids['l1'])
        ->and($data->firstWhere('id', $ids['l1'])['parent_id'])->toBeNull()
        ->and($data->pluck('title')->all())->toBe(sommaireOfficiel());
});

it('garde les labels de l\'éditeur, sans préfixe, résolus dans /tree', function () {
    ['document' => $document, 'ids' => $ids] = codeAPlusieursNiveaux(prefixeIngestion: false);

    $data = collect($this->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertSuccessful()
        ->json('data'));

    expect($data->firstWhere('id', $ids['c3'])['parent_id'])->toBe($ids['t2'])
        ->and($data->pluck('title')->all())->toBe(sommaireOfficiel());
});

it('sert le même arbre au téléchargement hors ligne', function () {
    ['document' => $document, 'ids' => $ids] = codeAPlusieursNiveaux();

    $nodes = collect($this->getJson("/api/v1/legal-documents/{$document->id}/download")
        ->assertSuccessful()
        ->json('data.nodes'));

    expect($nodes->pluck('parent_id')->filter()->diff($nodes->pluck('id')))->toBeEmpty()
        ->and($nodes->firstWhere('id', $ids['c4'])['parent_id'])->toBe($ids['t4'])
        ->and($nodes->pluck('title')->all())->toBe(sommaireOfficiel());
});

it('rattache la racine d\'un sous-arbre téléchargé à son vrai parent', function () {
    ['document' => $document, 'ids' => $ids] = codeAPlusieursNiveaux();

    $nodes = collect($this->getJson("/api/v1/legal-documents/{$document->id}/download?node_id={$ids['t1']}")
        ->assertSuccessful()
        ->json('data.nodes'));

    expect($nodes->pluck('id')->all())->toBe([$ids['t1'], $ids['c1'], $ids['c2']])
        ->and($nodes->first()['parent_id'])->toBe($ids['l1']);
});

it('sert le sommaire public par slug dans l\'ordre de lecture', function () {
    ['document' => $document, 'ids' => $ids] = codeAPlusieursNiveaux();

    $nodes = collect($this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=169")
        ->assertSuccessful()
        ->json('data.structure.nodes'));

    expect($nodes->pluck('title')->all())->toBe(sommaireOfficiel())
        ->and($nodes->firstWhere('id', $ids['c4'])['parent_id'])->toBe($ids['t4']);
});
