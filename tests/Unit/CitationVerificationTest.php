<?php

use App\Ai\AssistantChatService;
use App\Ai\CitationStreamFilter;

/**
 * Vérification des citations [n] de l'assistant (P2.8).
 *
 * Deux surfaces, une même règle : un marqueur [n] n'est conservé que s'il
 * pointe vers une source RÉELLEMENT retournée par le RAG.
 *  - `AssistantChatService::verifyCitations` : réponse complète (JSON sync,
 *    texte persisté, mise en cache).
 *  - `CitationStreamFilter` : flux SSE, fragment par fragment.
 * Ce sont des unités pures (aucune dépendance au conteneur) : on les teste sans
 * base de données ni faux fournisseur d'IA.
 */

/**
 * Fabrique une liste de sources numérotées 1..n (champ source_number).
 *
 * @return array<int, array{source_number: int}>
 */
function citationSources(int $count): array
{
    return array_map(fn (int $n): array => ['source_number' => $n], range(1, max(0, $count)) ?: []);
}

/**
 * Rejoue un texte streamé en fragments arbitraires à travers le filtre.
 *
 * @param  array<int, string>  $chunks
 * @param  array<int, mixed>  $sources
 */
function streamFiltered(array $chunks, array $sources): string
{
    $filter = new CitationStreamFilter;
    $filter->registerSources($sources);

    $output = '';
    foreach ($chunks as $chunk) {
        $output .= $filter->push($chunk);
    }

    return $output.$filter->flush();
}

describe('verifyCitations (réponse complète)', function () {
    it('keeps every marker when all point to a real source', function () {
        $service = new AssistantChatService;

        $reply = 'Le préavis est d\'un mois [2]. Voir aussi [1] et [3].';

        expect($service->verifyCitations($reply, citationSources(3)))
            ->toBe($reply);
    });

    it('drops an orphan marker while keeping valid ones', function () {
        $service = new AssistantChatService;

        // [5] est orphelin (seules 3 sources) : retiré avec l'espace qui le
        // précède ; [2] reste intact.
        expect($service->verifyCitations('Règle applicable [5]. Fin [2].', citationSources(3)))
            ->toBe('Règle applicable. Fin [2].');
    });

    it('strips every marker when no source was returned', function () {
        $service = new AssistantChatService;

        expect($service->verifyCitations('Texte [1] et [2] ici.', []))
            ->toBe('Texte et ici.');
    });

    it('leaves a marker-free reply untouched', function () {
        $service = new AssistantChatService;

        $reply = 'Aucune citation ici, rien à retirer.';

        expect($service->verifyCitations($reply, citationSources(3)))
            ->toBe($reply);
    });

    it('falls back to 1-based position when source_number is absent', function () {
        $service = new AssistantChatService;

        // Deux sources sans source_number : [1] et [2] valides, [3] orphelin.
        expect($service->verifyCitations('X [1] Y [2] Z [3]', [['id' => 'a'], ['id' => 'b']]))
            ->toBe('X [1] Y [2] Z');
    });

    it('does not swallow a newline before an orphan marker (markdown structure preserved)', function () {
        $service = new AssistantChatService;

        // Marqueur orphelin en début de ligne après un titre : on ne retire que
        // le marqueur (et un éventuel espace horizontal le précédant), JAMAIS le
        // saut de ligne — sinon le titre fusionnerait avec le corps au rendu
        // markdown. L'espace résiduel en tête de ligne est inoffensif.
        expect($service->verifyCitations("## Titre\n[9] Le contenu suit.", citationSources(3)))
            ->toBe("## Titre\n Le contenu suit.");
    });
});

describe('CitationStreamFilter (flux SSE)', function () {
    it('keeps valid markers streamed in one piece', function () {
        expect(streamFiltered(['Le préavis est un mois [2]. Voir [1].'], citationSources(3)))
            ->toBe('Le préavis est un mois [2]. Voir [1].');
    });

    it('removes an orphan marker (with its leading space)', function () {
        expect(streamFiltered(['Règle applicable [5]. Fin [2].'], citationSources(3)))
            ->toBe('Règle applicable. Fin [2].');
    });

    it('strips all markers when no source is registered', function () {
        expect(streamFiltered(['Texte [1] et [2] ici.'], []))
            ->toBe('Texte et ici.');
    });

    it('reassembles a valid marker split across fragments', function () {
        // « [2] » arrive scindé en trois deltas : le filtre le recompose et le
        // conserve puisque la source 2 existe.
        expect(streamFiltered(['Mois ', '[', '2', ']', ' fin.'], citationSources(3)))
            ->toBe('Mois [2] fin.');
    });

    it('removes an orphan marker split across fragments', function () {
        expect(streamFiltered(['Mois ', '[5', ']', ' fin.'], citationSources(3)))
            ->toBe('Mois fin.');
    });

    it('never eats non-citation brackets', function () {
        $text = 'Voir [texte entre crochets] ici.';

        expect(streamFiltered([$text], citationSources(3)))
            ->toBe($text);
    });

    it('emits an incomplete marker at end of stream instead of swallowing it', function () {
        // Flux tronqué sur « [5 » (jamais refermé) : on ne doit rien avaler.
        expect(streamFiltered(['Fin tronquée [5'], citationSources(3)))
            ->toBe('Fin tronquée [5');
    });

    it('accepts sources registered across several search calls (continuous numbering)', function () {
        $filter = new CitationStreamFilter;
        $filter->registerSources([['source_number' => 1], ['source_number' => 2]]);
        $filter->registerSources([['source_number' => 3]]);

        // [3] est valide (3e source), [4] est orphelin.
        $output = $filter->push('A [3] B [4] C').$filter->flush();

        expect($output)->toBe('A [3] B C');
    });

    it('handles a multi-digit valid marker and a multi-digit orphan', function () {
        $filter = new CitationStreamFilter;
        $filter->registerSources(citationSources(12));

        expect($filter->push('Ref [12] ok, ref [13] orphelin.').$filter->flush())
            ->toBe('Ref [12] ok, ref orphelin.');
    });
});
