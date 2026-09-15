<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\LegalDocument;

/**
 * Un détecteur du jeu v3 (mibeko-dashboard#141, § 3.5 du plan « boîte de
 * réception ») : une règle déterministe sur le CONTENU d'un document déjà
 * structuré, portée à l'identique depuis la requête SQL v2 du corps de
 * `mibeko-dashboard#23`, un par condition de son CTE `flags`/`d10` — ou un
 * ajout v3 (§ 3.5, pseudo-titre / doublon / séquence).
 *
 * Distinct de `StructuralAnomalyDetector` (arbre et position des feuilles,
 * sans lire le texte) : ici on lit `contenu_texte`/`titre_officiel`, jamais
 * l'arbre. Un détecteur ne connaît que son document ; un contrôle qui a
 * besoin du reste du corpus (doublon titre+date) interroge la base
 * lui-même — jamais de contexte partagé entre détecteurs.
 */
interface DetecteurContenu
{
    /**
     * Identifiant stable posé dans `curation_flags.type_probleme` — le code
     * du registre `#23` quand il existe (`d1_numero_doublon`…), un nom
     * descriptif sinon (aucun code n'a jamais été attribué à ce contrôle).
     */
    public function code(): string;

    /** `CurationFlag::SEVERITY_*` — jamais variable selon le candidat trouvé. */
    public function severity(): string;

    /**
     * Analyse un document et renvoie ses candidats de signalement — jamais
     * moins d'un candidat par occurrence réelle (§ 3, protocole : « chaque
     * détecteur produit un nombre, consigné même à zéro »).
     *
     * @return array<int, array{article_id?: string, description: string, anchor?: array<string, mixed>}>
     */
    public function detecter(LegalDocument $document): array;
}
