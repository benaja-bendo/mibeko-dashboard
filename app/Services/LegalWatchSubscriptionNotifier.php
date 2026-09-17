<?php

namespace App\Services;

use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\LegalWatchSubscription;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Alertes issues d'un abonnement EXPLICITE à un texte ou un thème
 * (`LegalWatchSubscription`, mibeko-dashboard#125) — distinctes de la veille
 * légale générale (`LegalWatchNotifier`), diffusée à tout utilisateur qui n'a
 * pas coupé `new_document`. Premier déclencheur livré ici : une
 * `DocumentRelation` ABROGE/MODIFIE/COMPLETE qui passe à `confirmed` alerte
 * les abonnés du texte CIBLE (celui qui « reçoit » la relation).
 *
 * Canal in-app uniquement pour cette passe (push et UI front restent en
 * reliquat, cf. le fil de #125). Gatée par `UserSetting::TYPE_LEGAL_ALERT` —
 * un type de préférence réservé de longue date mais jusqu'ici jamais consommé.
 */
class LegalWatchSubscriptionNotifier
{
    /** Valeur de `notifications.type` pour une alerte de relation confirmée. */
    public const TYPE_RELATION = 'legal_watch_relation';

    /**
     * Types de relation jugés pertinents pour un abonné — portée du ticket,
     * CREE/CITE/RENUMEROTE n'annoncent aucun changement du texte suivi.
     */
    private const RELEVANT_RELATION_TYPES = [
        DocumentRelation::TYPE_ABROGE,
        DocumentRelation::TYPE_MODIFIE,
        DocumentRelation::TYPE_COMPLETE,
    ];

    private const RELATION_VERBS = [
        DocumentRelation::TYPE_ABROGE => 'abrogé',
        DocumentRelation::TYPE_MODIFIE => 'modifié',
        DocumentRelation::TYPE_COMPLETE => 'complété',
    ];

    /**
     * Alerte les abonnés du texte cible d'une relation qui vient d'être
     * confirmée. Rien n'est envoyé si le texte cible (ou l'acte modificateur,
     * quand il est connu) n'est pas publié : exposer l'un ou l'autre
     * révélerait un brouillon.
     */
    public function relationConfirmed(DocumentRelation $relation): int
    {
        if ($relation->status !== DocumentRelation::STATUS_CONFIRMED
            || ! in_array($relation->relation_type, self::RELEVANT_RELATION_TYPES, true)
            || $relation->target_doc_id === null) {
            return 0;
        }

        $target = LegalDocument::query()
            ->where('id', $relation->target_doc_id)
            ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
            ->first(['id', 'titre_officiel', 'slug']);

        if ($target === null) {
            return 0;
        }

        $sourceTitle = null;

        if ($relation->source_doc_id !== null) {
            $source = LegalDocument::query()
                ->where('id', $relation->source_doc_id)
                ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
                ->first(['id', 'titre_officiel']);

            if ($source === null) {
                return 0;
            }

            $sourceTitle = $source->titre_officiel;
        }

        $subscriberIds = LegalWatchSubscription::query()
            ->where('watchable_type', LegalWatchSubscription::WATCHABLE_DOCUMENT)
            ->where('watchable_id', $target->id)
            ->pluck('user_id');

        if ($subscriberIds->isEmpty()) {
            return 0;
        }

        $verb = self::RELATION_VERBS[$relation->relation_type];
        $message = $sourceTitle !== null
            ? Str::limit("Ce texte a été {$verb} par : {$sourceTitle}", 200)
            : "Ce texte a été {$verb}.";

        return $this->notify(
            $subscriberIds,
            self::TYPE_RELATION,
            Str::limit((string) $target->titre_officiel, 250),
            $message,
            self::TYPE_RELATION.':'.$relation->id,
            [
                'type' => self::TYPE_RELATION,
                'document_id' => (string) $target->id,
                'slug' => (string) $target->slug,
                'relation_type' => $relation->relation_type,
                'url' => $this->documentUrl((string) $target->slug),
            ],
        );
    }

    /**
     * Écrit une ligne `notifications` in-app par abonné qui accepte le canal.
     * Insertion idempotente : la contrainte unique `(user_id, dedupe_key)`
     * (même mécanisme que la veille légale générale) empêche un doublon si ce
     * déclencheur est rejoué pour le même événement.
     *
     * @param  Collection<int, string>  $userIds
     * @param  array<string, string>  $data
     */
    private function notify(Collection $userIds, string $type, string $title, string $message, string $dedupeKey, array $data): int
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = now();

        $rows = User::query()
            ->whereIn('id', $userIds->unique())
            ->whereNull('suspended_at')
            ->with('settings')
            ->get()
            ->filter(fn (User $user) => $user->settings?->allowsNotification(UserSetting::TYPE_LEGAL_ALERT, 'in_app')
                ?? UserSetting::notificationDefaultFor(UserSetting::TYPE_LEGAL_ALERT, 'in_app'))
            ->map(fn (User $user) => [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'data' => $payload,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        Notification::insertOrIgnore($rows);

        return count($rows);
    }

    private function documentUrl(string $slug): string
    {
        if ($slug === '') {
            return '';
        }

        return rtrim((string) config('app.site_url'), '/').'/textes/'.$slug;
    }
}
