<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class MinerUClient
{
    protected string $baseUrl;

    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.mineru.api_url', 'https://mineru.net/api/v4');
        $this->apiKey = config('services.mineru.api_key', '');
    }

    /**
     * Envoie le fichier PDF à l'API MinerU et retourne le Markdown et métadonnées.
     *
     * @param  string  $fileContent  Contenu binaire du fichier PDF
     * @param  string  $filename  Nom du fichier original
     *
     * @throws Exception
     */
    public function extractPdf(string $fileContent, string $filename): array
    {
        if (empty($this->apiKey)) {
            throw new Exception("La clé API MinerU n'est pas configurée.");
        }

        Log::info("Extraction du fichier {$filename} via MinerU API...");

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];

        // 1. Demander une URL d'upload
        Log::debug("Demande d'URL d'upload pour {$filename}...");
        $response = Http::timeout(30)->withHeaders($headers)->post("{$this->baseUrl}/file-urls/batch", [
            'files' => [
                ['name' => $filename, 'is_ocr' => true],
            ],
        ]);

        if ($response->failed()) {
            throw new Exception("Erreur HTTP lors de la demande d'URL: ".$response->body());
        }

        $data = $response->json();
        if (($data['code'] ?? -1) !== 0) {
            throw new Exception('Erreur API MinerU: '.($data['msg'] ?? 'Inconnue'));
        }

        $batchId = $data['data']['batch_id'];
        $uploadUrl = $data['data']['file_urls'][0];

        // 2. Uploader le fichier
        Log::debug("Upload du fichier {$filename} vers l'URL signée...");
        $uploadResponse = Http::timeout(120)
            ->withOptions(['headers' => ['Content-Type' => '']])
            ->send('PUT', $uploadUrl, [
                'body' => $fileContent,
            ]);

        if ($uploadResponse->failed()) {
            throw new Exception("Erreur lors de l'upload du fichier: ".$uploadResponse->body());
        }

        // 3. Polling du statut
        Log::debug("Attente des résultats pour batch_id: {$batchId}...");
        $pollInterval = 5;
        $maxRetries = 60; // 5 minutes max
        $resultData = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            $pollResponse = Http::timeout(30)->withHeaders($headers)->get("{$this->baseUrl}/extract-results/batch/{$batchId}");

            if ($pollResponse->failed()) {
                throw new Exception('Erreur lors du polling: '.$pollResponse->body());
            }

            $pollData = $pollResponse->json();
            if (($pollData['code'] ?? -1) !== 0) {
                throw new Exception('Erreur API lors du polling: '.($pollData['msg'] ?? 'Inconnue'));
            }

            $extractResults = $pollData['data']['extract_result'] ?? [];
            if (empty($extractResults)) {
                throw new Exception('Aucun résultat dans la réponse de polling.');
            }

            $fileResult = $extractResults[0];
            $state = $fileResult['state'] ?? '';

            if ($state === 'done') {
                $resultData = $fileResult;
                break;
            } elseif ($state === 'failed') {
                $errMsg = $fileResult['err_msg'] ?? 'Erreur inconnue';
                throw new Exception("L'extraction a échoué: {$errMsg}");
            }

            sleep($pollInterval);
        }

        if (! $resultData) {
            throw new Exception('Le traitement MinerU a expiré.');
        }

        // 4. Télécharger le résultat (zip)
        $zipUrl = $resultData['full_zip_url'] ?? null;
        if (! $zipUrl) {
            throw new Exception('Aucune URL de téléchargement (full_zip_url) retournée.');
        }

        Log::debug("Téléchargement de l'archive de résultats...");
        $zipResponse = Http::timeout(120)->get($zipUrl);

        if ($zipResponse->failed()) {
            throw new Exception('Erreur lors du téléchargement du ZIP: '.$zipResponse->body());
        }

        // 5. Extraire le markdown du zip
        $markdownContent = '';

        // Créer un fichier temporaire pour le zip
        $tmpZipFile = tempnam(sys_get_temp_dir(), 'mineru_zip_');
        file_put_contents($tmpZipFile, $zipResponse->body());

        $zip = new ZipArchive;
        if ($zip->open($tmpZipFile) === true) {
            $mdFile = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_ends_with($stat['name'], '.md')) {
                    if ($stat['name'] === 'full.md') {
                        $mdFile = 'full.md';
                        break;
                    }
                    if (! $mdFile) {
                        $mdFile = $stat['name'];
                    }
                }
            }

            if ($mdFile) {
                $markdownContent = $zip->getFromName($mdFile);
            } else {
                Log::warning("Aucun fichier markdown trouvé dans l'archive MinerU.");
            }
            $zip->close();
        } else {
            unlink($tmpZipFile);
            throw new Exception("Impossible d'ouvrir l'archive téléchargée.");
        }

        unlink($tmpZipFile);

        Log::info("Extraction réussie pour {$filename}.");

        return [
            'markdown' => $markdownContent,
            'metadata' => [
                'batch_id' => $batchId,
                'status' => 'done',
                'raw_data' => $resultData,
            ],
        ];
    }
}
