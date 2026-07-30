<?php

namespace App\Services;

use App\Jobs\SendLegalWatchNotifications;
use App\Models\LegalDocument;
use App\Models\LegalWatchDispatch;
use App\Models\UserSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Producteur de la veille légale : annonce aux abonnés les textes qui viennent
 * d'être publiés (ligne `notifications` + push FCM).
 *
 * POURQUOI UN SERVICE APPELÉ EXPLICITEMENT, ET PAS UN OBSERVER
 * ------------------------------------------------------------
 * La publication a deux chemins et un seul passe par Eloquent :
 *  - `PATCH /v1/legal-documents/{id}` → `$document->update()` → événements
 *    Eloquent (c'est là que `LegalDocumentObserver` travaille) ;
 *  - `PATCH /v1/legal-documents/bulk` → `->update()` de masse via le query
 *    builder → AUCUN événement Eloquent.
 * Un producteur branché sur l'observer manquerait donc exactement le chemin par
 * lequel passe une publication de masse. Convertir le bulk en boucle Eloquent
 * pour « réparer » l'observer coûterait N requêtes et interdirait la synthèse
 * (chaque document serait annoncé isolément, sans vue d'ensemble du lot).
 * Le service est appelé une fois par opération de publication, reçoit le LOT
 * complet, et peut donc arbitrer entre alertes unitaires et synthèse.
 *
 * IDEMPOTENCE — DEUX ÉTAGES
 * -------------------------
 * 1. RÉSERVATION : `legal_documents.watch_notified_at` (voir la migration). La
 *    réservation se fait en une transaction verrouillée : deux publications
 *    concurrentes du même document ne peuvent pas la remporter toutes les deux,
 *    et republier / re-sauvegarder un texte déjà annoncé ne produit rien. La
 *    colonne n'est volontairement PAS `fillable` : seul ce service l'écrit.
 * 2. DIFFUSION : la même transaction crée une ligne `legal_watch_dispatches`
 *    (journal), qui porte l'état d'avancement de l'envoi. Un rejeu du job ne
 *    refait que ce qui n'avait pas abouti, et les lignes `notifications`
 *    portent une `dedupe_key` unique par destinataire. Corollaire : une
 *    réservation sans journal est impossible, donc une alerte réservée mais
 *    jamais diffusée est TOUJOURS visible (et rejouable par
 *    `mibeko:retry-legal-watch`).
 */
class LegalWatchNotifier
{
    /**
     * Type de la matrice `notification_preferences` qui gouverne cette veille
     * (« Nouveau document juridique publié »).
     */
    public const PREFERENCE_TYPE = 'new_document';

    /** Valeur de `notifications.type` pour une alerte portant sur un texte. */
    public const TYPE_DOCUMENT = 'legal_watch';

    /** Valeur de `notifications.type` pour une synthèse de publication de masse. */
    public const TYPE_DIGEST = 'legal_watch_digest';

    /**
     * Annonce les documents fraîchement publiés.
     *
     * Accepte librement les identifiants candidats : la réservation ne retient
     * que ceux qui sont réellement publiés, vivants, dotés d'un slug et jamais
     * annoncés. Les appelants n'ont donc pas à filtrer eux-mêmes.
     *
     * @param  array<int, string>  $documentIds
     * @return int Nombre de documents effectivement retenus pour l'annonce.
     */
    public function documentsPublished(array $documentIds): int
    {
        if ($documentIds === []) {
            return 0;
        }

        $this->ensureAnnounceableSlugs($documentIds);

        $claimed = $this->claim($documentIds);

        if ($claimed === []) {
            return 0;
        }

        SendLegalWatchNotifications::dispatch($claimed['dispatch_id']);

        return count($claimed['document_ids']);
    }

    /**
     * Répare les slugs manquants du lot AVANT la réservation.
     *
     * Le slug est la cible du deep link de l'alerte. La publication de masse est
     * un `UPDATE` de query builder, muet côté Eloquent : le `saving` qui pose
     * habituellement le slug n'y tourne pas, et un document ingéré par le
     * pipeline Python arrive de toute façon sans slug. Sans cette réparation,
     * l'alerte partait avec `slug: ""` — taper la notification retombait sur
     * l'accueil — et le document étant déjà marqué comme annoncé, plus aucun
     * rattrapage n'était possible.
     *
     * `protected` : la réparation est un point de couture (les tests la
     * neutralisent pour vérifier que la réservation refuse bien un document
     * resté sans slug).
     *
     * @param  array<int, string>  $documentIds
     */
    protected function ensureAnnounceableSlugs(array $documentIds): void
    {
        LegalDocument::backfillMissingSlugs($documentIds);
    }

    /**
     * Réserve les documents à annoncer, les marque, et ouvre le journal de
     * diffusion correspondant — le tout dans une seule transaction.
     *
     * Le marquage a lieu AVANT l'envoi (et non après) : en cas d'échec de la
     * file, mieux vaut une alerte perdue qu'une alerte envoyée deux fois. Le
     * journal ouvert ici est ce qui rend cette perte réparable (voir
     * `mibeko:retry-legal-watch`).
     *
     * @param  array<int, string>  $documentIds
     * @return array{dispatch_id: string, document_ids: array<int, string>}|array{}
     */
    private function claim(array $documentIds): array
    {
        return DB::transaction(function () use ($documentIds): array {
            $claimed = DB::table('legal_documents')
                ->whereIn('id', $documentIds)
                ->whereNull('deleted_at')
                ->whereNull('watch_notified_at')
                ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
                // Un document sans slug n'est pas annonçable : le deep link
                // serait vide. On le laisse DÉLIBÉRÉMENT non réservé plutôt que
                // de consommer son `watch_notified_at` pour une alerte
                // inexploitable — il sera annoncé à la publication suivante,
                // une fois son slug réparé (le backfill ci-dessus, ou la
                // commande planifiée `mibeko:backfill-document-slugs`).
                ->whereNotNull('slug')
                ->whereNot('slug', '')
                ->lockForUpdate()
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($claimed === []) {
                $this->logUnannouncedForMissingSlug($documentIds);

                return [];
            }

            // Query builder brut, et pas l'Eloquent builder : marquer l'annonce
            // ne doit pas toucher `updated_at`, qui pilote la fraîcheur du
            // corpus vue par l'app mobile (une alerte n'est pas une révision).
            DB::table('legal_documents')
                ->whereIn('id', $claimed)
                ->update(['watch_notified_at' => now()]);

            $dispatch = LegalWatchDispatch::create([
                'document_ids' => $claimed,
                'document_count' => count($claimed),
                'status' => LegalWatchDispatch::STATUS_PENDING,
            ]);

            $this->logUnannouncedForMissingSlug(array_diff($documentIds, $claimed));

            return ['dispatch_id' => $dispatch->id, 'document_ids' => $claimed];
        });
    }

    /**
     * Trace les documents publiés qu'aucune alerte ne couvrira faute de slug.
     *
     * Cas résiduel (slug impossible à générer, course avec une suppression) :
     * l'anomalie doit être visible, sans quoi le texte serait simplement muet.
     *
     * @param  array<int, string>  $candidateIds
     */
    private function logUnannouncedForMissingSlug(array $candidateIds): void
    {
        if ($candidateIds === []) {
            return;
        }

        $slugless = LegalDocument::missingSlug(array_values($candidateIds))
            ->whereNull('deleted_at')
            ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
            ->whereNull('watch_notified_at')
            ->pluck('id')
            ->all();

        if ($slugless !== []) {
            Log::warning('Veille légale : document publié sans slug, annonce reportée.', [
                'document_ids' => $slugless,
            ]);
        }
    }

    /**
     * L'utilisateur accepte-t-il la veille légale sur ce canal ?
     *
     * @param  string  $channel  L'un de UserSetting::NOTIFICATION_CHANNELS.
     */
    public static function accepts(?UserSetting $settings, string $channel): bool
    {
        return $settings?->allowsNotification(self::PREFERENCE_TYPE, $channel)
            ?? UserSetting::notificationDefaultFor(self::PREFERENCE_TYPE, $channel);
    }
}
