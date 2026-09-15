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
     * `empreinte_source` : le texte exact dont dépend l'anomalie (le contenu
     * d'un article, le titre officiel…) — sert à poser une empreinte de
     * contenu sur le signalement (§ 3.5) : une exception déjà résolue ne
     * ressort pas tant que ce texte précis n'a pas changé. Jamais absent :
     * sans lui, toute résolution humaine se comporterait comme si le contenu
     * changeait à chaque passage (aucune exception ne tiendrait jamais).
     *
     * @return array<int, array{article_id?: string, description: string, empreinte_source: string, anchor?: array<string, mixed>|null}>
     */
    public function detecter(LegalDocument $document): array;
}
