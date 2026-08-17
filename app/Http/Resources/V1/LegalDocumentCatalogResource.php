<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrée du catalogue consommée par l'app mobile pour détecter, sans
 * télécharger le corpus, ce qui a changé depuis sa dernière synchronisation.
 *
 * Le contrat est ADDITIF : les clients déjà publiés désérialisent ces champs
 * en types non-nullables sans valeur par défaut ; en retirer un casserait les
 * applications installées.
 */
class LegalDocumentCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->titre_officiel, // Use correct column name
            // Objet dérivé du corps de l'acte, pour les « actes en abrégé »
            // dont le titre se réduit au type, au numéro et à la date. Champ
            // ADDITIF et nullable : les clients déjà installés l'ignorent.
            // À afficher à côté du titre, jamais à sa place.
            'descriptive_label' => $this->libelle_descriptif,
            'type' => $this->type?->code ?? 'UNKNOWN',
            'version_hash' => $this->versionHash(),
            'last_updated' => $this->updated_at->toIso8601String(),
            'download_size_kb' => 0, // Placeholder
            'slug' => $this->slug,
            'consolidation_as_of' => $this->consolidation_as_of?->toDateString(),
            'articles_count' => (int) ($this->articles_count ?? 0),
        ];
    }

    /**
     * Empreinte de version du document.
     *
     * `updated_at` seul ne suffit pas : il ne bouge que sur écriture Eloquent,
     * or le pipeline Python écrit des articles directement. On y mêle donc
     * l'horodatage de l'article le plus récent et le nombre d'articles : toute
     * correction, tout ajout et toute suppression change l'empreinte, même si
     * la ligne du document n'a pas été touchée.
     */
    private function versionHash(): string
    {
        return md5(implode('|', [
            $this->updated_at->toIso8601String(),
            (string) ($this->articles_max_updated_at ?? ''),
            (string) ($this->articles_count ?? 0),
        ]));
    }
}
