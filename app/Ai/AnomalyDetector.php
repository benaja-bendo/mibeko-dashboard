<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
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
 * Sortie structurée (schéma strict natif Mistral) : le modèle renvoie
 * directement un objet `{anomalies: [...]}` — plus de parsing regex du texte.
 * On conserve la validation métier (refs connues, vocabulaire fermé, sévérité,
 * confiance) et l'échec gracieux (une panne IA ne crée aucun flag et ne casse
 * rien). Tâche assistive → modèle le moins cher du fournisseur ({@see UseCheapestModel}).
 */
#[UseCheapestModel]
class AnomalyDetector implements Agent, HasStructuredOutput
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

    /** Sévérités admises, de la plus grave à la plus mineure. */
    public const SEVERITIES = ['blocking', 'warning', 'info'];

    public function instructions(): Stringable|string
    {
        return 'Tu es un relecteur expert de textes juridiques de la République du Congo (Congo-Brazzaville) '
            ."et du droit OHADA. On te donne un lot d'éléments extraits d'un texte de loi (chacun avec sa "
            ."position dans l'arbre, son numéro et son contenu OCRisé). Tu repères UNIQUEMENT les défauts "
            ."d'EXTRACTION, pas les choix du législateur. Types autorisés : "
            .implode(', ', self::TYPES).'. '
            ."Sévérités : 'blocking' (contenu perdu/illisible, fusion d'articles), 'warning' (fragment, "
            ."classification douteuse, renvoi mort), 'info' (mineur). "
            ."Réutilise exactement les 'ref' fournies ; n'en invente pas. Si un élément est correct, ne le liste pas.";
    }

    /**
     * Schéma de sortie : la liste des anomalies détectées (vide si tout est correct).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'anomalies' => $schema->array()
                ->items($schema->object([
                    'ref' => $schema->string()->description('Ref de l\'élément concerné, parmi celles fournies.')->required(),
                    'type_probleme' => $schema->string()->enum(self::TYPES)->required(),
                    'severity' => $schema->string()->enum(self::SEVERITIES)->required(),
                    'description' => $schema->string()->description('Courte explication en français.')->required(),
                    'suggestion' => $schema->string()->nullable()->description('Correction proposée, ou null.'),
                    'confidence' => $schema->number()->nullable()->description('Confiance entre 0 et 1.'),
                ]))
                ->description('Anomalies d\'extraction détectées ; tableau vide si aucun défaut.'),
        ];
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
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // Échec gracieux : une panne IA (réseau, clé, quota) ne doit jamais
        // empêcher l'ingestion ni les couches déterministes.
        try {
            $response = $this->prompt($prompt);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $anomalies = is_array($response['anomalies'] ?? null) ? $response['anomalies'] : [];

        return $this->validateAnomalies($anomalies, collect($items)->pluck('ref')->all());
    }

    /**
     * Valide et normalise les anomalies renvoyées par le modèle (refs connues,
     * type et sévérité dans le vocabulaire fermé, suggestion/confiance propres).
     *
     * @param  array<int, mixed>  $anomalies
     * @param  array<int, string>  $validRefs
     * @return array<int, array<string, mixed>>
     */
    private function validateAnomalies(array $anomalies, array $validRefs): array
    {
        $valid = array_flip($validRefs);
        $clean = [];

        foreach ($anomalies as $anomaly) {
            if (! is_array($anomaly)) {
                continue;
            }

            $ref = $anomaly['ref'] ?? null;
            $type = $anomaly['type_probleme'] ?? null;

            if (! is_string($ref) || ! isset($valid[$ref]) || ! in_array($type, self::TYPES, true)) {
                continue;
            }

            $severity = in_array($anomaly['severity'] ?? null, self::SEVERITIES, true)
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
