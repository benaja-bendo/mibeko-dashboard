<?php

namespace App\Services;

use App\Models\Dossier;
use App\Support\ValeursAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Efface ce que l'usager a supprimé mais que la base gardait — décision du
 * 25/09/2026 (`docs/decisions.md`, dashboard#205).
 *
 * Un dossier supprimé reste en base comme tombstone : son identifiant, renvoyé
 * dans `deleted_ids`, prévient les autres appareils de la suppression, y
 * compris un téléphone resté des mois hors ligne. Il n'a besoin de rien
 * d'autre. Tout le reste part au moment même de la suppression : ce que
 * l'usager a écrit ou choisi, les annexes (articles et notes, échéances,
 * références, pièces, documents générés) et les valeurs que l'audit owen-it en
 * avait recopiées. Une échéance supprimée seule garde de même son tombstone,
 * prévu pour une future synchronisation, et perd son contenu.
 *
 * Compromis accepté : un appareil qui a modifié le dossier hors ligne APRÈS sa
 * suppression le fait revenir (last-write-wins de `DossierController::sync`)
 * avec ce qu'il détient. Pour le mobile, c'est tout ce qu'il synchronise ; les
 * champs « affaire » saisis sur le web et les annexes, eux, ne reviennent pas.
 *
 * Écritures par le query builder, jamais par Eloquent : un `update()` de modèle
 * ferait écrire à owen-it une ligne `updated` recopiant le contenu au moment
 * même où on l'efface. Chaque écriture ne vise que ce qui subsiste encore :
 * rejouer ne touche rien, et une mesure annonce exactement ce qu'une
 * exécution touchera.
 */
class EffaceurContenuSupprime
{
    /**
     * Ce que garde un dossier supprimé : de quoi propager la suppression
     * (`id`, `user_id`, `deleted_at`, `client_updated_at` pour le
     * last-write-wins) et les horodatages de la ligne, que l'usager ne saisit pas.
     */
    public const DOSSIER_CONSERVE = ['id', 'user_id', 'deleted_at', 'client_updated_at', 'client_created_at', 'created_at', 'updated_at'];

    /**
     * Tout ce que l'usager a écrit ou choisi, ramené au défaut du schéma.
     * `name` n'a pas de défaut et refuse NULL : la chaîne vide, qu'aucun
     * dossier vivant ne porte (validation `required`), marque le tombstone vidé.
     */
    public const DOSSIER_EFFACE = [
        'name' => '',
        'legal_domain' => 'Général',
        'tag' => 'EN_COURS',
        'description' => null,
        'color' => '#1B3D2F',
        'type' => 'contentieux',
        'status' => 'ouvert',
        'internal_reference' => null,
        'client_name' => null,
        'client_role' => null,
        'adverse_party' => null,
        'jurisdiction' => null,
        'nature' => null,
    ];

    /**
     * Tables rattachées à un dossier par `dossier_id` : leurs lignes partent
     * avec le contenu du dossier supprimé.
     */
    public const ANNEXES = ['dossier_articles', 'dossier_echeances', 'dossier_references', 'dossier_pieces', 'dossier_generated_documents'];

    /** Ce que garde une échéance supprimée seule, sur le modèle du dossier. */
    public const ECHEANCE_CONSERVE = ['id', 'dossier_id', 'deleted_at', 'client_updated_at', 'client_created_at', 'created_at', 'updated_at'];

    /** Contenu d'une échéance ramené au défaut du schéma (`title` : même règle que `name`). */
    public const ECHEANCE_EFFACE = [
        'type' => 'autre',
        'title' => '',
        'due_date' => null,
        'status' => 'a_venir',
        'trigger_event' => null,
        'trigger_date' => null,
        'rule_id' => null,
        'basis_article_id' => null,
        'is_confirmed' => false,
        'reminders' => null,
        'note' => null,
    ];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Dossiers supprimés dont quelque chose subsiste (colonne saisie, annexe
     * ou valeur d'audit), du plus anciennement supprimé au plus récent.
     *
     * @return list<string>
     */
    public function dossiersAEffacer(int $limite = 0): array
    {
        $requete = $this->db->table('dossiers')
            ->whereNotNull('deleted_at')
            ->where(function (Builder $contenu) {
                $contenu->where(fn (Builder $ligne) => $this->subsiste($ligne, self::DOSSIER_EFFACE));

                foreach (self::ANNEXES as $annexe) {
                    $contenu->orWhereExists(fn (Builder $sous) => $sous->selectRaw('1')
                        ->from($annexe)
                        ->whereColumn("{$annexe}.dossier_id", 'dossiers.id'));
                }

                $contenu->orWhereExists(fn (Builder $sous) => $this->auditsAvecValeurs($sous->selectRaw('1')->from($this->tableAudits()))
                    ->whereRaw('auditable_id = dossiers.id::text'));
            })
            ->orderBy('deleted_at')
            ->orderBy('id');

        if ($limite > 0) {
            $requete->limit($limite);
        }

        return $requete->pluck('id')->all();
    }

    /**
     * Échéances supprimées une à une dont le contenu subsiste. Celles d'un
     * dossier supprimé n'en font pas partie : elles partent avec ses annexes.
     *
     * @return list<string>
     */
    public function echeancesAEffacer(int $limite = 0): array
    {
        $requete = $this->db->table('dossier_echeances')
            ->whereNotNull('deleted_at')
            ->whereIn('dossier_id', fn (Builder $vivants) => $vivants->select('id')->from('dossiers')->whereNull('deleted_at'))
            ->where(fn (Builder $ligne) => $this->subsiste($ligne, self::ECHEANCE_EFFACE))
            ->orderBy('deleted_at')
            ->orderBy('id');

        if ($limite > 0) {
            $requete->limit($limite);
        }

        return $requete->pluck('id')->all();
    }

    /**
     * Ce que l'effacement de ces dossiers toucherait, par table, sans rien écrire.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public function mesurerDossiers(array $ids): array
    {
        return $this->traiterDossiers($ids, ecrire: false);
    }

    /**
     * Efface le contenu de ces dossiers, s'ils sont toujours supprimés : un
     * appareil a pu en faire revenir un depuis leur sélection.
     *
     * @param  list<string>  $ids
     * @return array<string, int> lignes touchées par table
     */
    public function effacerDossiers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->db->transaction(function () use ($ids): array {
            $supprimes = $this->db->table('dossiers')
                ->whereIn('id', $ids)
                ->whereNotNull('deleted_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            return $this->traiterDossiers($supprimes, ecrire: true);
        });
    }

    /**
     * Vide ces échéances, si elles sont toujours supprimées.
     *
     * @param  list<string>  $ids
     */
    public function effacerEcheances(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->subsiste(
            $this->db->table('dossier_echeances')->whereIn('id', $ids)->whereNotNull('deleted_at'),
            self::ECHEANCE_EFFACE,
        )->update([...self::ECHEANCE_EFFACE, 'updated_at' => now()]);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function traiterDossiers(array $ids, bool $ecrire): array
    {
        if ($ids === []) {
            return [];
        }

        $touches = [];

        // 1. Le journal : on garde l'événement, la date et les champs modifiés,
        //    pas ce qu'ils valaient.
        $audits = $this->auditsAvecValeurs($this->db->table($this->tableAudits()))->whereIn('auditable_id', $ids);
        $touches[$this->tableAudits()] = $ecrire
            ? $audits->update([
                'old_values' => $this->db->raw(ValeursAudit::effacees('old_values')),
                'new_values' => $this->db->raw(ValeursAudit::effacees('new_values')),
            ])
            : $audits->count();

        // 2. Les annexes : le tombstone suffit à propager la suppression.
        foreach (self::ANNEXES as $annexe) {
            $lignes = $this->db->table($annexe)->whereIn('dossier_id', $ids);
            $touches[$annexe] = $ecrire ? $lignes->delete() : $lignes->count();
        }

        // 3. La ligne du dossier, ramenée à son tombstone.
        $ligne = $this->subsiste($this->db->table('dossiers')->whereIn('id', $ids), self::DOSSIER_EFFACE);
        $touches['dossiers'] = $ecrire
            ? $ligne->update([...self::DOSSIER_EFFACE, 'updated_at' => now()])
            : $ligne->count();

        return array_filter($touches);
    }

    /**
     * Restreint la requête aux lignes dont une colonne diffère encore de sa
     * valeur effacée.
     *
     * @param  array<string, string|bool|null>  $efface
     */
    private function subsiste(Builder $requete, array $efface): Builder
    {
        return $requete->where(function (Builder $ecart) use ($efface) {
            foreach ($efface as $colonne => $valeur) {
                match (true) {
                    $valeur === null => $ecart->orWhereNotNull($colonne),
                    is_bool($valeur) => $ecart->orWhereRaw("{$colonne} is distinct from ".($valeur ? 'true' : 'false')),
                    default => $ecart->orWhereRaw("{$colonne} is distinct from ?", [$valeur]),
                };
            }
        });
    }

    private function auditsAvecValeurs(Builder $requete): Builder
    {
        return $requete
            ->where('auditable_type', (new Dossier)->getMorphClass())
            ->whereRaw('('.ValeursAudit::subsistent('old_values').' or '.ValeursAudit::subsistent('new_values').')');
    }

    private function tableAudits(): string
    {
        return (string) config('audit.drivers.database.table', 'audits');
    }
}
