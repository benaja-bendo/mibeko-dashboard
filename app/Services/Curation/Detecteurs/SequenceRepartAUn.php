<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\CurationFlag;
use App\Models\LegalDocument;

/**
 * Ajout v3 (protocole de validation, étape 2) : la numérotation des
 * articles redémarre plusieurs fois en cours de document — « le défaut le
 * plus grave et le plus discret » de la session fondatrice du protocole,
 * signature d'un second texte bundlé dans le même PDF.
 *
 * Réutilise le signal de `count_series_restarts` (`mibeko-python`,
 * `src/services/ingestion.py`) : une chute vers un petit numéro
 * (`<= RESTART_LOW`) après un numéro élevé (`> RESTART_PREV_MIN`) compte un
 * redémarrage ; `MIN_RESTARTS` reprend le seuil qui classe l'ingestion
 * Python en « compilation » plutôt qu'en simple série secondaire incrustée
 * (une annexe légitime, `warning`, pas `blocking`). Volontairement PLUS
 * simple que l'algorithme Python complet (`find_embedded_series_runs`,
 * distinction série confirmée / réapparition isolée) : ce détecteur ne
 * signale que le cas sans ambiguïté — au moins deux redémarrages francs —
 * pour tourner sur tout le corpus sans réanalyser au fil de l'eau la nuance
 * que l'ingestion a déjà tranchée une première fois.
 */
class SequenceRepartAUn implements DetecteurContenu
{
    private const RESTART_LOW = 3;

    private const RESTART_PREV_MIN = 10;

    private const MIN_RESTARTS = 2;

    public function code(): string
    {
        return 'sequence_repart_a_un';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    public function detecter(LegalDocument $document): array
    {
        $ordinaux = $document->articles()
            ->orderBy('ordre_affichage')
            ->pluck('numero_article')
            ->map(fn (?string $numero) => $this->versEntier($numero))
            ->filter(fn (?int $n) => $n !== null)
            ->values();

        $redemarrages = 0;
        $precedent = null;
        foreach ($ordinaux as $nombre) {
            if ($precedent !== null && $nombre <= self::RESTART_LOW && $precedent > self::RESTART_PREV_MIN) {
                $redemarrages++;
            }
            $precedent = $nombre;
        }

        if ($redemarrages < self::MIN_RESTARTS) {
            return [];
        }

        return [[
            'description' => "La numérotation des articles redémarre {$redemarrages} fois : compilation probable de plusieurs textes distincts dans le même document.",
            'anchor' => null,
        ]];
    }

    private function versEntier(?string $numero): ?int
    {
        if ($numero === null || preg_match('/^(\d+)/', trim($numero), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
