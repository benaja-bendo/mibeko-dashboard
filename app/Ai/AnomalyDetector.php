<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Détecteur SÉMANTIQUE d'anomalies d'extraction (couche LLM).
 *
 * On lui soumet un lot de feuilles (articles) avec leur POSITION dans l'arbre
 * (fil d'Ariane) et leur CONTENU. Il signale les défauts que les règles
 * déterministes ne voient pas : découpe ratée (articles fusionnés), fragment,
 * mauvaise classification (titre rangé en article), renvoi interne mort,
 * charabia OCR, numéro incohérent. Posture strictement assistive : il SIGNALE
 * et peut PROPOSER une correction, jamais l'appliquer.
 *
 * Calqué sur {@see ThemeClassifier} : sortie JSON stricte, parsing robuste au
 * bavardage, échec gracieux (une panne IA ne crée aucun flag et ne casse rien).
 */
class AnomalyDetector implements Agent
{
    use Promptable;

    /** Familles d'anomalies de contenu attendues (vocabulaire fermé). */
    public const TYPES = [
        'contenu_tronque',
        'decoupe_fusion',
        'decoupe_fragment',
        'mauvaise_classification',
        'renvoi_incoherent',
        'charabia_ocr',
        'numero_incoherent',
    ];

    public function instructions(): Stringable|string
    {
        return 'Tu es un relecteur expert de textes juridiques de la République du Congo (Congo-Brazzaville) '
            ."et du droit OHADA. On te donne un lot d'éléments extraits d'un texte de loi (chacun avec sa "
            ."position dans l'arbre, son numéro et son contenu OCRisé). Tu repères UNIQUEMENT les défauts "
            ."d'EXTRACTION, pas les choix du législateur. Types autorisés : "
            .implode(', ', self::TYPES).'. '
            ."Sévérités : 'blocking' (contenu perdu/illisible, fusion d'articles), 'warning' (fragment, "
            ."classification douteuse, renvoi mort), 'info' (mineur). "
            .'Réponds STRICTEMENT par un objet JSON {"anomalies": [{"ref": "<ref fournie>", "type_probleme": '
            .'"<type>", "severity": "<sévérité>", "description": "<courte explication en français>", '
            .'"suggestion": "<correction proposée ou null>", "confidence": <0..1>}]}, sans aucun autre texte '
            .'ni balise de code. N\'invente pas de ref. Si un élément est correct, ne le liste pas.';
    }

    /**
     * Analyse un lot de feuilles et renvoie les anomalies détectées.
     *
     * @param  array<int, array{ref:string, breadcrumb:string, numero:string, contenu:string}>  $items
     * @return array<int, array{ref:string, type_probleme:string, severity:string, description:string, suggestion:?string, confidence:?float}>
     */
    public function analyze(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $payload = collect($items)
            ->map(fn ($item) => [
                'ref' => $item['ref'],
                'position' => $item['breadcrumb'] ?? '',
                'numero' => $item['numero'] ?? '',
                'contenu' => mb_substr((string) ($item['contenu'] ?? ''), 0, 2000),
            ])
            ->values()
            ->all();

        $prompt = "Éléments à contrôler (JSON) :\n"
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nRéponds en JSON : {\"anomalies\": [...]}.";

        // Échec gracieux : une panne IA (réseau, clé, quota) ne doit jamais
        // empêcher l'ingestion ni les couches déterministes.
        try {
            $response = $this->prompt($prompt);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return $this->parseAnomalies($response->text, collect($items)->pluck('ref')->all());
    }

    /**
     * Extrait et valide les anomalies de la réponse (robuste au bavardage).
     *
     * @param  array<int, string>  $validRefs
     * @return array<int, array<string, mixed>>
     */
    private function parseAnomalies(string $text, array $validRefs): array
    {
        if (! preg_match('/\{.*\}/s', $text, $matches)) {
            return [];
        }

        $decoded = json_decode($matches[0], true);
        $anomalies = is_array($decoded['anomalies'] ?? null) ? $decoded['anomalies'] : [];

        $valid = array_flip($validRefs);
        $clean = [];

        foreach ($anomalies as $anomaly) {
            $ref = $anomaly['ref'] ?? null;
            $type = $anomaly['type_probleme'] ?? null;

            if (! is_string($ref) || ! isset($valid[$ref]) || ! in_array($type, self::TYPES, true)) {
                continue;
            }

            $severity = in_array($anomaly['severity'] ?? null, ['blocking', 'warning', 'info'], true)
                ? $anomaly['severity']
                : 'warning';

            $suggestion = ($anomaly['suggestion'] ?? null);
            $suggestion = (is_string($suggestion) && trim($suggestion) !== '' && strtolower($suggestion) !== 'null')
                ? $suggestion
                : null;

            $clean[] = [
                'ref' => $ref,
                'type_probleme' => $type,
                'severity' => $severity,
                'description' => is_string($anomaly['description'] ?? null) ? $anomaly['description'] : '',
                'suggestion' => $suggestion,
                'confidence' => isset($anomaly['confidence']) ? (float) $anomaly['confidence'] : null,
            ];
        }

        return $clean;
    }
}
