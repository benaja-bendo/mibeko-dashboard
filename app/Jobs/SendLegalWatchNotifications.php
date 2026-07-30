<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\LegalDocument;
use App\Models\LegalWatchDispatch;
use App\Models\Notification;
use App\Models\User;
use App\Services\LegalWatchNotifier;
use App\Services\PushNotificationService;
use App\Support\AppVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Diffuse la veille légale d'un lot de documents déjà réservés par
 * `LegalWatchNotifier` : écriture des lignes `notifications` (canal in-app) puis
 * envoi des push (canal push, délégué à des jobs par tranche de jetons).
 *
 * Mis en file pour que la réponse HTTP de la publication ne dépende ni du
 * nombre d'utilisateurs ni de FCM, et chunké de bout en bout : ni la liste des
 * utilisateurs ni celle des appareils ne sont chargées en mémoire d'un bloc.
 *
 * REJEU
 * -----
 * Le job travaille sur une ligne `legal_watch_dispatches` et non sur une simple
 * liste d'identifiants : la file le rejoue (`tries = 3`), et sans état partagé
 * un échec survenu APRÈS l'écriture des notifications réémettait tout —
 * doublons dans le centre de notifications et second push sur le téléphone. Le
 * journal porte donc l'avancement par étape, et chaque ligne `notifications`
 * porte une `dedupe_key` unique par destinataire : un rejeu ne peut rien
 * réinsérer, même s'il reprend une étape à zéro.
 */
class SendLegalWatchNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * Utilisateurs traités par tranche lors de l'écriture des lignes in-app.
     */
    private const USER_CHUNK = 500;

    /**
     * Appareils lus par tranche avant regroupement en jobs d'envoi.
     */
    private const DEVICE_CHUNK = 500;

    /**
     * @param  string  $dispatchId  Lot réservé (`legal_watch_dispatches`).
     */
    public function __construct(public string $dispatchId) {}

    public function handle(): void
    {
        $dispatch = LegalWatchDispatch::find($this->dispatchId);

        if ($dispatch === null) {
            Log::warning('SendLegalWatchNotifications: lot introuvable, diffusion abandonnée.', [
                'dispatch_id' => $this->dispatchId,
            ]);

            return;
        }

        // Rejeu d'un lot déjà diffusé : la file peut redonner un job dont
        // l'accusé de traitement s'est perdu. Rien à refaire.
        if ($dispatch->status === LegalWatchDispatch::STATUS_DELIVERED) {
            return;
        }

        $dispatch->increment('attempts');

        $documents = LegalDocument::query()
            ->whereIn('id', $dispatch->document_ids)
            ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
            ->orderBy('created_at')
            ->get(['id', 'titre_officiel', 'slug']);

        if ($documents->isEmpty()) {
            // Plus rien à annoncer (dépublication ou suppression entre-temps) :
            // on solde le lot, sinon il resterait éternellement « en attente »
            // et le rattrapage le rejouerait à l'infini.
            $this->markDelivered($dispatch);

            return;
        }

        $alerts = $this->alerts($dispatch, $documents);

        // Étapes gardées séparément : reprendre un lot dont les notifications
        // in-app sont déjà écrites ne doit pas les réécrire, et inversement.
        if ($dispatch->in_app_written_at === null) {
            foreach ($alerts as $alert) {
                $this->writeInAppNotifications($alert);
            }

            $dispatch->forceFill(['in_app_written_at' => now()])->save();
        }

        if ($dispatch->pushes_dispatched_at === null) {
            foreach ($alerts as $alert) {
                $this->dispatchPushes($alert);
            }

            $dispatch->forceFill(['pushes_dispatched_at' => now()])->save();
        }

        $this->markDelivered($dispatch);
    }

    private function markDelivered(LegalWatchDispatch $dispatch): void
    {
        $dispatch->forceFill([
            'status' => LegalWatchDispatch::STATUS_DELIVERED,
            'delivered_at' => now(),
            'last_error' => null,
        ])->save();
    }

    /**
     * Compose les alertes à diffuser : une par texte tant que le lot reste
     * lisible, une synthèse unique au-delà du seuil configuré
     * (`mobile.watch.digest_threshold`).
     *
     * @param  EloquentCollection<int, LegalDocument>  $documents
     * @return array<int, array{title: string, message: string, type: string, dedupe_key: string, data: array<string, string>}>
     */
    private function alerts(LegalWatchDispatch $dispatch, EloquentCollection $documents): array
    {
        $threshold = max(1, (int) config('mobile.watch.digest_threshold', 3));

        if ($documents->count() > $threshold) {
            return [$this->digestAlert($dispatch, $documents)];
        }

        return $documents->map(fn (LegalDocument $document) => $this->documentAlert($document))->all();
    }

    /**
     * @return array{title: string, message: string, type: string, dedupe_key: string, data: array<string, string>}
     */
    private function documentAlert(LegalDocument $document): array
    {
        $slug = (string) $document->slug;

        // Toutes les valeurs de `data` sont des chaînes : FCM l'exige, et le
        // modèle mobile `NotificationRemote` la désérialise en Map<String,String>.
        $data = [
            'type' => LegalWatchNotifier::TYPE_DOCUMENT,
            'document_id' => (string) $document->id,
            'slug' => $slug,
        ];

        if ($slug !== '') {
            // L'app résout ces deux formes via son écran TexteResolver
            // (App Links https://mibeko.fr/textes/{slug} et scheme mibeko://).
            $data['url'] = rtrim((string) config('app.site_url'), '/').'/textes/'.$slug;
            $data['deeplink'] = 'mibeko://textes/'.$slug;
        }

        return [
            'title' => 'Nouveau texte publié',
            'message' => Str::limit((string) $document->titre_officiel, 200),
            'type' => LegalWatchNotifier::TYPE_DOCUMENT,
            // Clé stable dans le temps : un document n'est réservé qu'une fois
            // (`watch_notified_at`), donc deux lots ne peuvent pas l'annoncer.
            'dedupe_key' => LegalWatchNotifier::TYPE_DOCUMENT.':doc:'.$document->id,
            'data' => $data,
        ];
    }

    /**
     * @param  EloquentCollection<int, LegalDocument>  $documents
     * @return array{title: string, message: string, type: string, dedupe_key: string, data: array<string, string>}
     */
    private function digestAlert(LegalWatchDispatch $dispatch, EloquentCollection $documents): array
    {
        $count = $documents->count();

        return [
            'title' => "{$count} nouveaux textes publiés",
            'message' => "Le corpus Mibeko s'est enrichi de {$count} nouveaux textes.",
            'type' => LegalWatchNotifier::TYPE_DIGEST,
            // La synthèse n'a pas de document unique auquel s'accrocher : c'est
            // le lot lui-même qui l'identifie.
            'dedupe_key' => LegalWatchNotifier::TYPE_DIGEST.':batch:'.$dispatch->id,
            'data' => [
                'type' => LegalWatchNotifier::TYPE_DIGEST,
                'count' => (string) $count,
                'url' => rtrim((string) config('app.site_url'), '/').'/textes',
            ],
        ];
    }

    /**
     * Écrit une ligne `notifications` pour chaque utilisateur qui accepte la
     * veille sur le canal in-app.
     *
     * Insertion groupée : `insertOrIgnore()` court-circuite les casts, la
     * charge `data` est donc encodée à la main et l'UUID généré ici (la valeur
     * par défaut Postgres ne s'applique pas aux colonnes explicitement
     * fournies). `insertOrIgnore` (et non `insert`) + l'unicité
     * `(user_id, dedupe_key)` : un rejeu du job après un échec au milieu des
     * tranches d'utilisateurs ne redonne pas une seconde alerte aux premiers.
     *
     * @param  array{title: string, message: string, type: string, dedupe_key: string, data: array<string, string>}  $alert
     */
    private function writeInAppNotifications(array $alert): void
    {
        $payload = json_encode($alert['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = now();

        User::query()
            ->whereNull('suspended_at')
            ->with('settings')
            ->chunkById(self::USER_CHUNK, function (EloquentCollection $users) use ($alert, $payload, $now): void {
                $rows = $users
                    ->filter(fn (User $user) => LegalWatchNotifier::accepts($user->settings, 'in_app'))
                    ->map(fn (User $user) => [
                        'id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'title' => Str::limit($alert['title'], 250),
                        'message' => $alert['message'],
                        'type' => $alert['type'],
                        'dedupe_key' => $alert['dedupe_key'],
                        'data' => $payload,
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    Notification::insertOrIgnore($rows);
                }
            });
    }

    /**
     * Regroupe les jetons éligibles et confie chaque tranche à un job d'envoi.
     *
     * Cette étape n'appelle pas FCM : elle ne fait que mettre en file. Le seul
     * rejeu qui pourrait doubler un push est celui d'un job interrompu PENDANT
     * cette boucle (jetons déjà mis en file, `pushes_dispatched_at` pas encore
     * posé) — fenêtre de quelques millisecondes sans appel réseau, assumée : la
     * fermer exigerait un journal par (lot, jeton), coûteux pour un risque
     * marginal, alors que les erreurs réelles (FCM injoignable) surviennent
     * dans `SendLegalWatchPush`, qui est rejoué seul.
     *
     * Le découpage est double : d'abord par FORME de message (l'app publiée
     * n'affiche pas un data-only), puis en tranches de `push_chunk_size` à
     * l'intérieur de chaque forme. Un appareil ne peut appartenir qu'à une
     * forme, donc aucun jeton n'est servi deux fois.
     *
     * @param  array{title: string, message: string, type: string, dedupe_key: string, data: array<string, string>}  $alert
     */
    private function dispatchPushes(array $alert): void
    {
        $chunkSize = max(1, (int) config('mobile.watch.push_chunk_size', 100));

        Device::query()
            ->pushable()
            ->with('user.settings')
            ->chunkById(self::DEVICE_CHUNK, function (EloquentCollection $devices) use ($alert, $chunkSize): void {
                foreach ($this->tokensByMessageFormat($devices) as $format => $tokens) {
                    foreach (array_chunk($tokens, $chunkSize) as $chunk) {
                        SendLegalWatchPush::dispatch(
                            $chunk,
                            $alert['title'],
                            $alert['message'],
                            $alert['data'],
                            $format,
                        );
                    }
                }
            });
    }

    /**
     * Répartit les jetons d'une tranche selon la forme de message que l'app
     * installée sait AFFICHER.
     *
     * Le serveur vise le data-only (seule forme qui préserve le deep link), mais
     * les versions publiées avant la 1.2 ne lisent que `remoteMessage.notification`
     * et n'afficheraient rien. Un appareil dont la version est inconnue (colonne
     * `app_version` nulle : tout le parc installé avant que l'app ne l'annonce)
     * est donc traité comme ancien — un push visible qui ouvre l'accueil vaut
     * mieux qu'un push invisible, d'autant que `watch_notified_at` consomme
     * l'alerte définitivement.
     *
     * @param  EloquentCollection<int, Device>  $devices
     * @return array<string, array<int, string>> jetons indexés par forme, formes vides écartées
     */
    private function tokensByMessageFormat(EloquentCollection $devices): array
    {
        $minimumVersion = (string) config('mobile.watch.data_only_min_app_version', '1.2.0');

        $formatByToken = [];

        foreach ($devices as $device) {
            if (! $this->deviceAcceptsWatch($device)) {
                continue;
            }

            $token = (string) $device->push_token;

            if ($token === '') {
                continue;
            }

            // Même jeton porté par deux lignes `devices` (réinstallation qui
            // change l'identifiant d'appareil) : la forme héritée l'emporte,
            // c'est la seule qui s'affiche sur les deux générations d'app.
            if (($formatByToken[$token] ?? null) === PushNotificationService::FORMAT_LEGACY) {
                continue;
            }

            $formatByToken[$token] = AppVersion::atLeast($device->app_version, $minimumVersion)
                ? PushNotificationService::FORMAT_DATA_ONLY
                : PushNotificationService::FORMAT_LEGACY;
        }

        $grouped = [
            PushNotificationService::FORMAT_DATA_ONLY => [],
            PushNotificationService::FORMAT_LEGACY => [],
        ];

        foreach ($formatByToken as $token => $format) {
            // Transtypage : PHP convertit en entier une clé de tableau
            // entièrement numérique, et un jeton FCM factice peut l'être.
            $grouped[$format][] = (string) $token;
        }

        return array_filter($grouped, fn (array $tokens): bool => $tokens !== []);
    }

    /**
     * Un appareil sans propriétaire reçoit la veille GÉNÉRALE : c'est le mode
     * invité de l'app mobile, il n'existe aucune préférence à consulter et
     * l'enregistrement du jeton vaut demande d'alerte. Un appareil rattaché suit
     * les préférences de son propriétaire ; si celui-ci a été supprimé ou
     * suspendu, l'appareil est ignoré (il ne redevient pas « invité »).
     */
    private function deviceAcceptsWatch(Device $device): bool
    {
        if ($device->user_id === null) {
            return true;
        }

        $user = $device->user;

        if ($user === null || $user->suspended_at !== null) {
            return false;
        }

        return LegalWatchNotifier::accepts($user->settings, 'push');
    }

    public function failed(\Throwable $exception): void
    {
        // Le lot reste en base, marqué en échec : c'est la seule trace d'une
        // alerte réservée (les documents portent déjà `watch_notified_at`) mais
        // jamais diffusée. `mibeko:retry-legal-watch` la rejoue.
        LegalWatchDispatch::where('id', $this->dispatchId)
            ->whereNot('status', LegalWatchDispatch::STATUS_DELIVERED)
            ->update([
                'status' => LegalWatchDispatch::STATUS_FAILED,
                'last_error' => Str::limit($exception->getMessage(), 1000),
                'updated_at' => now(),
            ]);

        Log::error('SendLegalWatchNotifications: diffusion échouée — '.$exception->getMessage(), [
            'dispatch_id' => $this->dispatchId,
        ]);
    }
}
