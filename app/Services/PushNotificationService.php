<?php

namespace App\Services;

use App\Models\Device;
use Google\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    protected string $projectId;

    protected string $credentialsPath;

    protected string $fcmUrl;

    /**
     * Clé de cache du jeton OAuth2 Google (valable ~1 h côté Google).
     */
    private const ACCESS_TOKEN_CACHE_KEY = 'fcm:access_token';

    /**
     * Forme CIBLE du message : aucun bloc `notification`, titre et corps dans
     * `data`. Seule forme qui garantit le deep link, mais elle exige une app qui
     * sache construire elle-même la notification (v1.2+). Cf. `sendV1()`.
     */
    public const FORMAT_DATA_ONLY = 'data_only';

    /**
     * Forme HÉRITÉE : bloc `notification` (affiché par le système) + le même
     * `data`. Destinée aux apps publiées avant la v1.2, qui ne lisent que
     * `remoteMessage.notification` : l'alerte s'affiche, le tap retombe sur
     * l'accueil quand l'app est en arrière-plan. Cf. `sendV1()`.
     */
    public const FORMAT_LEGACY = 'legacy';

    public function __construct()
    {
        // Transtypage explicite : hors production, la configuration Firebase est
        // absente et l'affectation d'un `null` à une propriété typée `string`
        // faisait planter la simple instanciation du service.
        $this->projectId = (string) config('services.firebase.project_id');
        $this->credentialsPath = (string) config('services.firebase.credentials_path');
        $this->fcmUrl = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
    }

    /**
     * Get OAuth2 Access Token using Google API Client
     *
     * Le jeton est mis en cache : l'envoi de la veille légale est découpé en
     * plusieurs jobs, qui referaient chacun l'échange OAuth complet.
     */
    protected function getAccessToken(): ?string
    {
        // Jeton fourni par la configuration : permet de viser un bac à sable ou
        // un double de test sans exiger un fichier de credentials Google.
        $configured = (string) config('services.firebase.access_token');

        if ($configured !== '') {
            return $configured;
        }

        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->fetchAccessToken();

        if ($token !== null) {
            Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, now()->addMinutes(50));
        }

        return $token;
    }

    /**
     * Échange la clé de service Google contre un jeton d'accès FCM.
     */
    private function fetchAccessToken(): ?string
    {
        try {
            if (! file_exists($this->credentialsPath)) {
                Log::error("PushNotificationService: Credentials file not found at {$this->credentialsPath}");

                return null;
            }

            $client = new Client;
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $token = $client->fetchAccessTokenWithAssertion();

            return $token['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('PushNotificationService: Failed to get access token: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Send a push notification to multiple devices (FCM HTTP v1).
     *
     * Le lot doit être HOMOGÈNE en forme de message : l'appelant regroupe ses
     * jetons par génération d'app avant d'appeler (cf.
     * `SendLegalWatchNotifications::dispatchPushes`). Le défaut reste la cible
     * `FORMAT_DATA_ONLY` ; `FORMAT_LEGACY` s'obtient explicitement, en
     * connaissance de cause.
     *
     * @param  array  $data  Additional data for redirection
     * @param  string  $format  `FORMAT_DATA_ONLY` (défaut) ou `FORMAT_LEGACY`
     * @return array Summary of success and failures
     */
    public function sendToDevices(array $deviceTokens, string $title, string $body, array $data = [], string $format = self::FORMAT_DATA_ONLY): array
    {
        if (empty($deviceTokens)) {
            return ['success' => 0, 'failure' => 0];
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            Log::error('PushNotificationService: Cannot send notifications without access token.');

            return ['success' => 0, 'failure' => count($deviceTokens)];
        }

        $results = [
            'success' => 0,
            'failure' => 0,
            'invalid_tokens' => [],
        ];

        // FCM HTTP v1 requires sending messages one by one.
        foreach ($deviceTokens as $token) {
            $response = $this->sendV1($token, $accessToken, $title, $body, $data, $format);

            if ($response && $response->successful()) {
                $results['success']++;
            } else {
                $results['failure']++;

                // Handle invalid tokens (v1 returns 404 NOT_FOUND or 400 INVALID_ARGUMENT with specific error codes)
                $error = $response ? $response->json('error.status') : 'UNKNOWN';
                $errorMessage = $response ? $response->json('error.message') : 'No response from FCM';
                Log::error("PushNotificationService: FCM error for token $token: $error - $errorMessage");

                if ($response && ($response->status() === 404 || $response->status() === 410)) {
                    $results['invalid_tokens'][] = $token;
                }
            }
        }

        // Clean up invalid tokens
        if (! empty($results['invalid_tokens'])) {
            Device::whereIn('push_token', $results['invalid_tokens'])->update(['status' => 'inactive']);
            Log::info('PushNotificationService: Desactivated '.count($results['invalid_tokens']).' invalid tokens.');
        }

        // La forme est journalisée : c'est elle qui explique une alerte reçue
        // mais jamais affichée sur un téléphone donné.
        Log::info("PushNotificationService: Sent notification '$title' (format: $format). Success: {$results['success']}, Failure: {$results['failure']}");

        return $results;
    }

    /**
     * Send a single message via FCM HTTP v1
     *
     * MESSAGE « DATA-ONLY » CÔTÉ ANDROID — POURQUOI
     * ---------------------------------------------
     * Le message ne porte volontairement AUCUN bloc `notification` de premier
     * niveau, ni bloc `android.notification`. Comportement FCM documenté : dès
     * qu'un de ces blocs est présent et que l'app Android est en arrière-plan ou
     * tuée, le système AFFICHE la notification lui-même et n'appelle PAS
     * `onMessageReceived`. Or c'est exactement là que l'app lit le `data`
     * (`slug`, `article`) pour construire le deep link : avec un bloc
     * `notification`, taper l'alerte en arrière-plan — le cas normal — retombait
     * toujours sur l'accueil.
     *
     * Le compromis assumé : un message data-only n'est pas affiché par le
     * système, c'est l'app qui construit la notification depuis `data["title"]`
     * et `data["message"]` (cf. `MyFirebaseMessagingService`). Il faut donc que
     * le processus applicatif puisse être réveillé — d'où `android.priority =
     * high`, qui sort l'app de Doze et fait délivrer le message immédiatement.
     * Une app *force-stoppée* par l'utilisateur ne recevra rien : c'est le prix
     * d'un deep link fiable, et il est préférable à une notification qui
     * n'ouvre jamais le bon texte.
     *
     * iOS n'est pas concerné par cette classification : le bloc `apns` ne part
     * qu'à APNs et porte l'alerte affichable, l'affichage système y reste donc
     * inchangé.
     *
     * FORME HÉRITÉE (`FORMAT_LEGACY`) — POUR QUI
     * ------------------------------------------
     * L'app publiée sur les stores (v1.0/v1.1) ne traite que
     * `remoteMessage.notification` : un message data-only lui arrive bien, mais
     * elle n'affiche RIEN. Tant que le parc n'a pas basculé en v1.2, un appareil
     * dont la version est antérieure — ou inconnue — reçoit donc un bloc
     * `notification` de premier niveau EN PLUS du `data`. Compromis : le système
     * affiche l'alerte (elle est visible), le tap en arrière-plan retombe sur
     * l'accueil (le deep link est perdu). Le `data` reste identique dans les
     * deux formes, ce qui laisse la v1.2 en avant-plan construire son lien
     * profond même si le serveur s'est trompé de génération.
     */
    protected function sendV1(string $token, string $accessToken, string $title, string $body, array $data, string $format = self::FORMAT_DATA_ONLY)
    {
        try {
            // Ensure data values are strings (FCM requirement)
            $formattedData = [];
            foreach ($data as $key => $value) {
                $formattedData[(string) $key] = (string) $value;
            }

            // Titre et corps voyagent DANS `data` : sans bloc `notification`,
            // c'est la seule façon pour l'app de les retrouver. Les clés du
            // `data` d'appel ne sont pas écrasées (un appelant peut vouloir un
            // libellé différent de celui affiché sur iOS).
            $formattedData += [
                'title' => $title,
                'message' => $body,
            ];

            $payload = [
                'token' => $token,
                'android' => [
                    // Obligatoire pour un message data-only : en priorité
                    // normale, Android peut retenir le message jusqu'à la
                    // prochaine fenêtre de maintenance (Doze).
                    'priority' => 'high',
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
                'data' => $formattedData,
            ];

            if ($format === self::FORMAT_LEGACY) {
                // Placé en tête pour rester lisible dans les journaux FCM : ce
                // bloc est justement ce qui distingue les deux formes.
                $payload = ['notification' => ['title' => $title, 'body' => $body]] + $payload;
            }

            return Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, [
                'message' => $payload,
            ]);
        } catch (\Exception $e) {
            Log::error('PushNotificationService sendV1 Error: '.$e->getMessage());

            return null;
        }
    }
}
