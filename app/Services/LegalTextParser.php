<?php

namespace App\Services;

class LegalTextParser
{
    /**
     * Nettoie le contenu pour corriger les erreurs communes d'OCR.
     */
    public function sanitizeContent(string $content): string
    {
        $replacements = [
            '/\bL0I\b/i' => 'LOI',
            '/\bArtide\b/i' => 'Article',
            '/\bDÉCRÊT\b/i' => 'DECRET',
            '/\bARRETÊ\b/i' => 'ARRETE',
            '/\bN°\s*o\b/i' => 'N°',
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Détecte le type de texte juridique à partir du titre.
     */
    public function detectTexteType(string $title): string
    {
        $title = trim($title);

        if (preg_match('/^(?:Loi constitutionnelle|LOI CONSTITUTIONNELLE)/i', $title)) {
            return 'LOI_CONSTITUTIONNELLE';
        } elseif (preg_match('/^(?:Loi|LOI)\b/i', $title)) {
            return 'LOI';
        } elseif (preg_match('/^(?:Décret|Decret|DECRET|DÉCRET|Décrêt|DECRÊT)\b/i', $title)) {
            return 'DECRET';
        } elseif (preg_match('/^(?:Arrêté|Arrete|Arreté|Arrête|ARRETE|ARRÊTÉ|ARRETÉ)\b/i', $title)) {
            return 'ARRETE';
        } elseif (preg_match('/^(?:Convention|CONVENTION)\b/i', $title)) {
            return 'CONVENTION';
        } elseif (preg_match('/^(?:Délibération|Deliberation|DELIBERATION|DÉLIBÉRATION)\b/i', $title)) {
            return 'DELIBERATION';
        } elseif (preg_match('/^(?:Protocole|PROTOCOLE)\b/i', $title)) {
            return 'PROTOCOLE';
        }

        $titleUpper = mb_strtoupper($title);
        if (str_contains($titleUpper, 'LOI')) {
            return 'LOI';
        }
        if (str_contains($titleUpper, 'DECRET') || str_contains($titleUpper, 'DÉCRET')) {
            return 'DECRET';
        }
        if (str_contains($titleUpper, 'ARRETE') || str_contains($titleUpper, 'ARRÊTÉ') || str_contains($titleUpper, 'ARRETÉ')) {
            return 'ARRETE';
        }
        if (str_contains($titleUpper, 'CONSTITUTION')) {
            return 'CONSTITUTION';
        }

        return 'TEXTE';
    }

    /**
     * Parse le contenu hiérarchique en structure Titre/Chapitre/Section/Article.
     */
    public function parseHierarchicalContent(string $content): array
    {
        $lines = explode("\n", $content);
        $result = [];

        $divisionStack = [];
        $currentArticle = null;
        $currentArticleLines = [];

        $divisionLevels = ['Titre', 'Chapitre', 'Section', 'Sous-section', 'Paragraphe'];

        $saveArticle = function () use (&$currentArticle, &$currentArticleLines, &$divisionStack, &$result) {
            if ($currentArticle) {
                $articleText = trim(implode("\n", $currentArticleLines));

                $articleObj = [
                    'type' => 'Article',
                    'numero' => $currentArticle,
                    'texte' => $articleText,
                ];

                if (! empty($divisionStack)) {
                    $lastIndex = count($divisionStack) - 1;
                    $divisionStack[$lastIndex]['elements'][] = $articleObj;
                } else {
                    $result[] = $articleObj;
                }

                $currentArticle = null;
                $currentArticleLines = [];
            }
        };

        foreach ($lines as $line) {
            $lineStripped = trim($line);

            if (empty($lineStripped)) {
                if ($currentArticle) {
                    $currentArticleLines[] = '';
                }

                continue;
            }

            // Match divisions (Titre, Chapitre, etc.)
            if (preg_match('/^(?:#+\s*)?(TITRE|Chapitre|Section|Sous-section|Paragraphe)\s+([IVXLCDM0-9]+(?:er|ème|e)?|premi(?:er|ère|ere)|un)(?:\s*[:：-]\s*(.+))?\.?$/iu', $lineStripped, $divisionMatch)) {
                $saveArticle();

                $divType = ucfirst(strtolower($divisionMatch[1]));
                if (strtolower($divType) === 'titre') {
                    $divType = 'Titre';
                }

                $numero = $divisionMatch[2];
                $intituleText = isset($divisionMatch[3]) ? trim($divisionMatch[3]) : '';

                $fullIntitule = strtoupper($divType).' '.$numero;
                if ($intituleText) {
                    $fullIntitule .= ' : '.$intituleText;
                }

                $divisionObj = [
                    'type' => $divType,
                    'numero' => $numero,
                    'intitule' => $fullIntitule,
                    'elements' => [],
                ];

                $currentLevel = array_search($divType, $divisionLevels);
                if ($currentLevel === false) {
                    $currentLevel = 0;
                }

                while (! empty($divisionStack)) {
                    $lastDiv = end($divisionStack);
                    $lastLevel = array_search($lastDiv['type'], $divisionLevels);
                    if ($lastLevel === false) {
                        $lastLevel = 0;
                    }

                    if ($lastLevel >= $currentLevel) {
                        $completedDiv = array_pop($divisionStack);
                        if (! empty($divisionStack)) {
                            $lastIndex = count($divisionStack) - 1;
                            $divisionStack[$lastIndex]['elements'][] = $completedDiv;
                        } else {
                            $result[] = $completedDiv;
                        }
                    } else {
                        break;
                    }
                }

                $divisionStack[] = $divisionObj;

                continue;
            }

            // Match Articles
            if (preg_match('/^(?:Article|Art\.|ART\.)\s+([\d]+(?:er|ème|°|re)?|premier|un)\s*[.：:]?\s*(?:[-–—]\s*)?(.*)$/i', $lineStripped, $articleMatch)) {
                $saveArticle();

                $currentArticle = $articleMatch[1];
                $contentStart = trim($articleMatch[2]);
                $currentArticleLines = $contentStart ? [$contentStart] : [];

                continue;
            }

            if ($currentArticle) {
                $currentArticleLines[] = $line;
            }
        }

        $saveArticle();

        while (! empty($divisionStack)) {
            $completedDiv = array_pop($divisionStack);
            if (! empty($divisionStack)) {
                $lastIndex = count($divisionStack) - 1;
                $divisionStack[$lastIndex]['elements'][] = $completedDiv;
            } else {
                $result[] = $completedDiv;
            }
        }

        return $result;
    }

    /**
     * Point d'entrée principal.
     */
    public function parse(string $markdownText): array
    {
        $content = $this->sanitizeContent($markdownText);
        $structure = $this->parseHierarchicalContent($content);

        return $structure;
    }

    /**
     * Sépare un Journal Officiel (markdown) en plusieurs textes juridiques.
     * Retourne un tableau avec le titre et le contenu de chaque texte trouvé.
     */
    public function splitOfficialJournal(string $markdownText): array
    {
        $content = $this->sanitizeContent($markdownText);
        $lines = explode("\n", $content);

        $texts = [];
        $currentTextLines = [];
        $currentTitle = null;

        // Regex pour identifier le début d'un texte juridique typique dans un JO
        // Ex: LOI N° 12-2023 du ...
        // DECRET N° ...
        $titleRegex = '/^(?:#+\s*)?(LOI|D[EÉ]CRET|ARR[EÊ]T[EÉ]|ORDONNANCE|D[EÉ]CISION|CIRCULAIRE|AVIS)\s+(?:N[°o]|n[°o])?\s*.*$/i';

        foreach ($lines as $line) {
            $lineStripped = trim($line);

            if (preg_match($titleRegex, $lineStripped)) {
                // Sauvegarder le texte précédent s'il y en a un
                if ($currentTitle) {
                    $texts[] = [
                        'titre' => $currentTitle,
                        'contenu' => implode("\n", $currentTextLines),
                        'type' => $this->detectTexteType($currentTitle),
                    ];
                }

                $currentTitle = preg_replace('/^#+\s*/', '', $lineStripped); // Enlever les hashtags markdown
                $currentTextLines = [$line];
            } else {
                if ($currentTitle) {
                    $currentTextLines[] = $line;
                }
            }
        }

        // Sauvegarder le dernier texte
        if ($currentTitle) {
            $texts[] = [
                'titre' => $currentTitle,
                'contenu' => implode("\n", $currentTextLines),
                'type' => $this->detectTexteType($currentTitle),
            ];
        }

        return $texts;
    }
}
