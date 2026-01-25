<?php

namespace App\Services;

use App\Models\Device;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    protected string $projectId;
    protected string $credentialsPath;
    protected string $fcmUrl;

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id');
        $this->credentialsPath = config('services.firebase.credentials_path');
        $this->fcmUrl = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
    }

    /**
     * Get OAuth2 Access Token using Google API Client
     */
    protected function getAccessToken(): ?string
    {
        try {
            if (!file_exists($this->credentialsPath)) {
                Log::error("PushNotificationService: Credentials file not found at {$this->credentialsPath}");
                return null;
            }

            $client = new Client();
            $client->setAuthConfig($this->credentialsPath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

            $token = $client->fetchAccessTokenWithAssertion();

            return $token['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error("PushNotificationService: Failed to get access token: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send a push notification to multiple devices (FCM HTTP v1).
     *
     * @param array $deviceTokens
     * @param string $title
     * @param string $body
     * @param array $data Additional data for redirection
     * @return array Summary of success and failures
     */
    public function sendToDevices(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        if (empty($deviceTokens)) {
            return ['success' => 0, 'failure' => 0];
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Log::error("PushNotificationService: Cannot send notifications without access token.");
            return ['success' => 0, 'failure' => count($deviceTokens)];
        }

        $results = [
            'success' => 0,
            'failure' => 0,
            'invalid_tokens' => []
        ];

        // FCM HTTP v1 requires sending messages one by one.
        foreach ($deviceTokens as $token) {
            $response = $this->sendV1($token, $accessToken, $title, $body, $data);

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
        if (!empty($results['invalid_tokens'])) {
            Device::whereIn('push_token', $results['invalid_tokens'])->update(['status' => 'inactive']);
            Log::info('PushNotificationService: Desactivated ' . count($results['invalid_tokens']) . ' invalid tokens.');
        }

        Log::info("PushNotificationService: Sent notification '$title'. Success: {$results['success']}, Failure: {$results['failure']}");

        return $results;
    }

    /**
     * Send a single message via FCM HTTP v1
     */
    protected function sendV1(string $token, string $accessToken, string $title, string $body, array $data)
    {
        try {
            // Ensure data values are strings (FCM requirement)
            $payload = [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
            ];

            if (!empty($data)) {
                $formattedData = [];
                foreach ($data as $key => $value) {
                    $formattedData[(string)$key] = (string)$value;
                }
                $payload['data'] = $formattedData;
            }

            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, [
                'message' => $payload,
            ]);
        } catch (\Exception $e) {
            Log::error('PushNotificationService sendV1 Error: ' . $e->getMessage());
            return null;
        }
    }
}
