<?php

namespace App\Jobs;

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Services\DocumentIngestionService;
use App\Services\LegalTextParser;
use App\Services\MinerUClient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessOfficialJournalExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    protected string $journalId;

    public function __construct(string $journalId)
    {
        $this->journalId = $journalId;
    }

    public function handle(MinerUClient $mineru, LegalTextParser $parser, DocumentIngestionService $ingester): void
    {
        Log::info("Début de l'extraction pour le JO: {$this->journalId}");

        $journal = OfficialJournal::find($this->journalId);
        if (! $journal) {
            Log::error("JO non trouvé: {$this->journalId}");

            return;
        }

        $journal->update(['transcription_status' => OfficialJournal::STATUS_IN_PROGRESS]);

        try {
            // 1. Lire le PDF
            $disk = str_contains($journal->file_path, 's3') ? 's3' : config('filesystems.default');
            $fileContent = Storage::disk($disk)->get($journal->file_path);

            if (! $fileContent) {
                throw new Exception("Impossible de lire le fichier JO: {$journal->file_path}");
            }

            $filename = basename($journal->file_path);

            // 2. Appel MinerU
            $mineruResult = $mineru->extractPdf($fileContent, $filename);
            $markdown = $mineruResult['markdown'];

            // 3. Séparer les textes juridiques du JO
            $texts = $parser->splitOfficialJournal($markdown);

            DB::transaction(function () use ($journal, $texts, $parser, $ingester) {
                foreach ($texts as $index => $textData) {
                    // Créer le LegalDocument
                    $document = LegalDocument::create([
                        'type_code' => 'TEXTE', // Idéalement mapper avec $textData['type'] s'il existe dans document_types
                        'official_journal_id' => $journal->id,
                        'document_role' => 'FLUX',
                        'titre_officiel' => $textData['titre'],
                        'date_publication' => $journal->publication_date,
                        'statut' => 'vigueur',
                        'curation_status' => LegalDocument::STATUS_DRAFT ?? 'draft',
                        'extraction_status' => 'succeeded',
                        'document_key' => 'jo-'.$journal->id.'-text-'.$index,
                    ]);

                    // Parser la hiérarchie du texte
                    $structure = $parser->parseHierarchicalContent($textData['contenu']);

                    // Ingester la structure
                    $sortOrder = 1;
                    $extractionId = (string) Str::uuid(); // Simulation d'un ID d'extraction pour le texte

                    // Optionnel: Enregistrer l'extraction brute pour ce texte
                    DB::table('text_extractions')->insert([
                        'id' => $extractionId,
                        'document_id' => $document->id,
                        'entity_type' => 'DOCUMENT',
                        'raw_text' => $textData['contenu'],
                        'extracted_at' => now(),
                    ]);

                    $ingester->ingestStructure($document, $structure, null, $sortOrder, $extractionId);
                }

                $journal->update(['transcription_status' => OfficialJournal::STATUS_COMPLETED]);
            });

            Log::info("Extraction JO terminée avec succès pour {$this->journalId}. Textes extraits: ".count($texts));

        } catch (Exception $e) {
            Log::error("Erreur d'extraction pour JO {$this->journalId}: ".$e->getMessage());
            $journal->update(['transcription_status' => OfficialJournal::STATUS_FAILED]);
        }
    }
}
