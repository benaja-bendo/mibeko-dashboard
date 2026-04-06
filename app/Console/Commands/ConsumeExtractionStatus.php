<?php

namespace App\Console\Commands;

use App\Events\DocumentExtractionUpdated;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Services\DocumentImportService;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConsumeExtractionStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-extraction-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume PDF extraction statuses from RabbitMQ';

    /**
     * Execute the console command.
     */
    public function handle(RabbitMQService $rabbitMQService, DocumentImportService $importService)
    {
        $this->info('Starting to consume pdf_extraction_status...');

        $rabbitMQService->consume('pdf_extraction_status', function ($data, $msg) use ($importService) {
            $this->info('Received status update: '.json_encode($data));

            try {
                $taskId = $data['task_id'] ?? null;
                $status = $data['status'] ?? null;

                if (! $taskId) {
                    Log::warning('Received extraction status without task_id', ['data' => $data]);

                    return;
                }

                $document = LegalDocument::find($taskId);

                if ($document) {
                    // Update document extraction status
                    $document->extraction_status = $status;
                    $document->save();

                    Log::info("Document {$taskId} extraction status updated to {$status}");

                    if ($status === 'completed') {
                        $resultPaths = $data['result_paths'] ?? [];

                        // Save Markdown and JSON paths as MediaFiles
                        if (! empty($resultPaths['markdown'])) {
                            $document->mediaFiles()->create([
                                'file_path' => $resultPaths['markdown'],
                                'mime_type' => 'text/markdown',
                                'description' => 'Texte extrait (Markdown)',
                            ]);
                        }

                        if (! empty($resultPaths['json'])) {
                            $document->mediaFiles()->create([
                                'file_path' => $resultPaths['json'],
                                'mime_type' => 'application/json',
                                'description' => 'Structure extraite (JSON)',
                            ]);

                            // Automatically import the extracted JSON content into the database
                            // Embeddings are NOT generated here — they are handled
                            // asynchronously by the scheduler via `mibeko:process-rag`
                            try {
                                $jsonContent = Storage::disk('s3')->get($resultPaths['json']);
                                if ($jsonContent) {
                                    $jsonData = json_decode($jsonContent, true);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        ArticleVersionObserver::$shouldSkipEmbeddings = true;

                                        try {
                                            DB::transaction(function () use ($importService, $document, $jsonData) {
                                                $importService->importContent($document, $jsonData);
                                            });
                                            Log::info("Successfully imported structured data for Document {$taskId}");
                                        } finally {
                                            ArticleVersionObserver::$shouldSkipEmbeddings = false;
                                        }
                                    } else {
                                        Log::error("Failed to decode JSON from MinIO for Document {$taskId}: ".json_last_error_msg());
                                    }
                                } else {
                                    Log::error("JSON file not found in MinIO for Document {$taskId} at path: {$resultPaths['json']}");
                                }
                            } catch (\Exception $e) {
                                Log::error("Error importing JSON content for Document {$taskId}: ".$e->getMessage());
                            }
                        }
                    }

                    // Broadcast event to update UI in real-time
                    broadcast(new DocumentExtractionUpdated($document));
                } else {
                    Log::warning("LegalDocument not found for task_id: {$taskId}");
                }

            } catch (\Exception $e) {
                Log::error('Error processing extraction status: '.$e->getMessage());
            }
        });
    }
}
