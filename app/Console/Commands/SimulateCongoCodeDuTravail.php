<?php

namespace App\Console\Commands;

use App\Jobs\ProcessDocumentExtraction;
use App\Models\Institution;
use App\Models\LegalDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('mibeko:simulate-code-du-travail
            {--path= : Chemin absolu ou relatif vers le PDF du Code du Travail}
            {--title= : Titre du document juridique}
            {--publication-date= : Date de publication (YYYY-MM-DD)}
            {--institution-sigle=METP : Sigle de l’institution}
            {--document-key=code-travail-1975 : Clé stable pour éviter les doublons}
            {--sync : Exécuter l’extraction immédiatement (sans queue)}
            {--disk= : Disque de stockage à utiliser (local, s3, etc.)}')]
#[Description('Simule la création d’un document juridique (upload PDF + job d’extraction) en suivant le même workflow que le backoffice.')]
class SimulateCongoCodeDuTravail extends Command
{
    public function handle(): int
    {
        $pdfPath = (string) ($this->option('path') ?: base_path('data/congo-code-1975-travail.pdf'));
        $title = (string) ($this->option('title') ?: 'Code du Travail');
        $publicationDate = $this->option('publication-date');
        $institutionSigle = (string) $this->option('institution-sigle');
        $documentKey = (string) $this->option('document-key');
        $shouldRunSync = (bool) $this->option('sync');

        if (! is_file($pdfPath)) {
            $this->error("PDF introuvable: {$pdfPath}");

            return self::FAILURE;
        }

        $institution = Institution::query()
            ->where('sigle', $institutionSigle)
            ->first();

        $date = null;
        if (is_string($publicationDate) && $publicationDate !== '') {
            $date = Carbon::parse($publicationDate);
        }

        $result = DB::transaction(function () use ($documentKey, $institution, $title, $date, $pdfPath, $shouldRunSync) {
            $document = LegalDocument::firstOrCreate(
                ['document_key' => $documentKey],
                [
                    'type_code' => 'CODE',
                    'institution_id' => $institution?->id,
                    'titre_officiel' => $title,
                    'date_publication' => $date?->toDateString(),
                    'curation_status' => LegalDocument::STATUS_DRAFT,
                    'statut' => 'vigueur',
                ],
            );

            if (! $document->wasRecentlyCreated) {
                return [$document, null, false];
            }

            $uploadedFile = new UploadedFile(
                $pdfPath,
                basename($pdfPath),
                'application/pdf',
                null,
                true
            );

            $filename = time().'_'.$uploadedFile->getClientOriginalName();
            $disk = $this->option('disk') ?: config('filesystems.default', 'local');
            $storedPath = $uploadedFile->storeAs('documents/pdfs', $filename, $disk);

            if (! $storedPath) {
                throw new \Exception("Échec de l'upload du fichier sur le disque: {$disk}");
            }

            $document->mediaFiles()->create([
                'file_path' => $storedPath,
                'mime_type' => 'application/pdf',
                'file_size' => filesize($pdfPath) ?: null,
                'description' => 'Original importé',
            ]);

            $document->update(['extraction_status' => 'processing']);

            $runId = (string) Str::uuid();
            DB::table('extraction_runs')->insert([
                'id' => $runId,
                'source' => 'PDF',
                'status' => 'queued',
                'started_at' => now(),
            ]);

            if ($shouldRunSync) {
                ProcessDocumentExtraction::dispatchSync((string) $document->id, $runId);
            } else {
                ProcessDocumentExtraction::dispatch((string) $document->id, $runId);
            }

            return [$document, $runId, true];
        });

        /** @var array{0: LegalDocument, 1: string|null, 2: bool} $result */
        [$document, $runId, $created] = $result;

        $this->info("Document: {$document->id}");
        $this->line("Titre: {$document->titre_officiel}");

        if (! $created) {
            $this->warn("Aucun import effectué: document_key déjà existant ({$documentKey}).");

            return self::SUCCESS;
        }

        $this->line("Run extraction: {$runId}");

        return self::SUCCESS;
    }
}
