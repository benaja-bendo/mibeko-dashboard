<?php

namespace App\Ai;

/**
 * Filtre de flux qui neutralise, à la volée, les marqueurs de citation [n]
 * orphelins d'une réponse streamée en SSE.
 *
 * Le modèle peut halluciner une référence : écrire « [5] » alors que seules
 * trois sources ont réellement été retournées par l'outil de recherche. Un tel
 * marqueur donne l'illusion d'un fondement légal inexistant — inacceptable sur
 * un produit dont la fiabilité juridique est le cœur de valeur. On le retire du
 * texte AVANT qu'il n'atteigne le client, sans attendre la fin de la génération.
 *
 * Fonctionnement : les fragments arrivent découpés arbitrairement (un marqueur
 * peut être scindé entre deux deltas : « [5 » puis « ] »). On accumule dans un
 * tampon tout suffixe qui pourrait être le DÉBUT d'un marqueur, on n'émet que ce
 * qui est certain, et lorsqu'un marqueur complet « [n] » est reconnu on décide :
 *  - n correspond à une source réelle → on l'émet tel quel ;
 *  - sinon → on le retire (avec l'espace qui le précédait, pour ne pas laisser
 *    de double espace ni d'espace avant ponctuation).
 *
 * L'ensemble des numéros valides s'enrichit au fil des résultats de recherche
 * (`registerSources`) : les marqueurs n'étant écrits qu'APRÈS les recherches,
 * l'ensemble est complet au moment de la décision.
 */
class CitationStreamFilter
{
    /**
     * Numéros de source réellement retournés par le RAG (source_number, sinon
     * position 1-based). Un marqueur [n] n'est conservé que si n y figure.
     *
     * @var array<int, true>
     */
    protected array $validNumbers = [];

    /**
     * Texte retenu qui pourrait être le début d'un marqueur ([, [1, [12, ou un
     * espace suivi de ce qui précède) et n'est donc pas encore émis.
     */
    protected string $buffer = '';

    /**
     * Enrichit l'ensemble des numéros de source valides avec un lot de sources.
     *
     * @param  array<int, mixed>  $sources  Sources d'un appel de l'outil de recherche.
     */
    public function registerSources(array $sources): void
    {
        foreach (array_values($sources) as $position => $source) {
            $number = is_array($source) && isset($source['source_number'])
                ? (int) $source['source_number']
                : $position + 1;
            $this->validNumbers[$number] = true;
        }
    }

    /**
     * Consomme un fragment de texte et retourne la portion sûre à émettre.
     *
     * Peut retourner une chaîne vide si tout le fragment reste en attente (cas
     * d'un marqueur en cours de constitution à cheval sur plusieurs fragments).
     */
    public function push(string $delta): string
    {
        $this->buffer .= $delta;

        return $this->drain(false);
    }

    /**
     * Vide le tampon en fin de flux : ce qui restait « en attente » est un
     * marqueur incomplet (jamais refermé) ou du texte ordinaire — on l'émet tel
     * quel, sans jamais avaler de contenu réel.
     */
    public function flush(): string
    {
        return $this->drain(true);
    }

    /**
     * Émet tout ce qui est décidable du tampon ; conserve en attente le seul
     * suffixe encore ambigu (sauf en fin de flux, où l'on émet tout).
     */
    protected function drain(bool $final): string
    {
        $output = '';

        while ($this->buffer !== '') {
            $bracket = strpos($this->buffer, '[');

            // Aucun crochet ouvrant : on peut tout émettre, sauf un espace final
            // qui pourrait précéder un futur marqueur à retirer (on le garde en
            // attente pour pouvoir l'absorber). En fin de flux, on émet tout.
            if ($bracket === false) {
                if (! $final && str_ends_with($this->buffer, ' ')) {
                    $output .= substr($this->buffer, 0, -1);
                    $this->buffer = ' ';

                    return $output;
                }

                $output .= $this->buffer;
                $this->buffer = '';

                return $output;
            }

            // Un marqueur retiré absorbe l'espace qui le précède. On garde donc
            // en attente un éventuel espace juste avant le crochet ; tout ce qui
            // précède cet espace est sûr et peut être émis.
            $prefixEnd = $bracket;
            if ($bracket > 0 && $this->buffer[$bracket - 1] === ' ') {
                $prefixEnd = $bracket - 1;
            }
            $output .= substr($this->buffer, 0, $prefixEnd);
            $this->buffer = substr($this->buffer, $prefixEnd);

            // Le tampon commence désormais par (un espace optionnel puis) '['.
            // Tente de reconnaître un marqueur complet « [n] ».
            if (preg_match('/^( ?)\[(\d+)\]/', $this->buffer, $matches)) {
                $number = (int) $matches[2];
                if (isset($this->validNumbers[$number])) {
                    // Marqueur fondé : émis tel quel (espace éventuel compris).
                    $output .= $matches[0];
                } // sinon : marqueur orphelin retiré (espace précédent absorbé).

                $this->buffer = substr($this->buffer, strlen($matches[0]));

                continue;
            }

            // Pas (encore) un marqueur complet. Est-ce un préfixe plausible
            // (« [ », « [1 », «  [12 ») qui pourrait se compléter au fragment
            // suivant ? Si oui et qu'on n'est pas en fin de flux, on attend.
            if (! $final && preg_match('/^ ?\[\d*$/', $this->buffer)) {
                return $output;
            }

            // Séquence « [… » qui ne deviendra jamais un marqueur (ex. « [voir »)
            // ou fin de flux : on émet le crochet et on poursuit après lui.
            $keep = $matches[1] ?? '';
            $lead = ($this->buffer[0] === ' ') ? ' ' : '';
            $output .= $lead.'[';
            $this->buffer = substr($this->buffer, strlen($lead) + 1);
            unset($keep);
        }

        return $output;
    }
}
