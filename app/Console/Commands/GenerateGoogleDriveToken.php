<?php

namespace App\Console\Commands;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Console\Command;

class GenerateGoogleDriveToken extends Command
{
    protected $signature = 'gdrive:token';

    protected $description = 'Génère un nouveau refresh token pour Google Drive API';

    public function handle()
    {
        $clientId = env('GOOGLE_DRIVE_CLIENT_ID');
        $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET');

        if (! $clientId || ! $clientSecret) {
            $this->error("Veuillez d'abord configurer GOOGLE_DRIVE_CLIENT_ID et GOOGLE_DRIVE_CLIENT_SECRET dans votre .env");

            return self::FAILURE;
        }

        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);

        // Important: pour les Web Apps ou "Desktop apps", il faut souvent un uri de redirection ou "urn:ietf:wg:oauth:2.0:oob"
        // Google a déprécié le OOB pour certains types de clients, mais pour tester en CLI on peut essayer OOB ou un localhost.
        $client->setRedirectUri('http://127.0.0.1:8000/google-callback'); // Vous pouvez adapter si besoin

        $client->setScopes([Drive::DRIVE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent'); // Force l'apparition du consentement pour avoir un refresh_token

        $authUrl = $client->createAuthUrl();

        $this->info("1. Ouvrez ce lien dans votre navigateur : \n\n$authUrl\n");

        // Si vous utilisez une Web App, Google redirigera vers http://localhost:8000/google-callback?code=...
        // Vous devez copier le paramètre 'code' de l'URL
        $authCode = $this->ask('2. Entrez le code d\'autorisation renvoyé par Google (le paramètre `code` dans l\'URL) :');

        if (! $authCode) {
            $this->error('Code manquant.');

            return self::FAILURE;
        }

        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            if (array_key_exists('error', $accessToken)) {
                throw new \Exception(implode(', ', $accessToken));
            }
        } catch (\Exception $e) {
            $this->error('Erreur lors de la récupération du token : '.$e->getMessage());

            return self::FAILURE;
        }

        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            $this->error("Le token a expiré immédiatement (problème d'horloge ou de validité).");

            return self::FAILURE;
        }

        $refreshToken = $client->getRefreshToken();

        if ($refreshToken) {
            $this->info('=== SUCCÈS ===');
            $this->line('Voici votre nouveau GOOGLE_DRIVE_REFRESH_TOKEN :');
            $this->comment($refreshToken);
            $this->line('');
            $this->info('Copiez-le et collez-le dans votre fichier .env.');
        } else {
            $this->warn("Le processus a réussi mais Google n'a pas renvoyé de refresh_token.");
            $this->line("Cela arrive si vous avez déjà autorisé l'application récemment. Allez dans les paramètres de votre compte Google -> Sécurité -> Applications tierces, révoquez l'accès à l'application et recommencez cette commande.");

            // Pour le debug, on affiche ce qu'on a reçu
            $this->line('Réponse brute de Google :');
            $this->line(json_encode($accessToken, JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
