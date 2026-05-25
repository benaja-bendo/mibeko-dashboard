<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOfficialJournalExtraction;
use App\Services\OfficialJournalService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

#[Signature('mibeko:simulate-jo {--path= : Chemin absolu ou relatif vers le PDF du JO} {--title= : Titre du Journal Officiel} {--publication-date= : Date de publication (YYYY-MM-DD)} {--published=1 : 1 pour publier, 0 pour brouillon}')]
#[Description('Simule la création d’un Journal Officiel (upload PDF + création en base) en suivant le même workflow que le backoffice.')]
class SimulateCongoOfficialJournal extends Command
{
    public function handle(OfficialJournalService $journalService): int
    {
        $pdfPath = (string) ($this->option('path') ?: base_path('data/congo-jo-2025-36.pdf'));
        $title = (string) ($this->option('title') ?: 'Journal Officiel - République du Congo - 2025 - n° 36');
        $publicationDate = $this->option('publication-date');
        $isPublished = filter_var($this->option('published'), FILTER_VALIDATE_BOOL);

        if (! is_file($pdfPath)) {
            $this->error("PDF introuvable: {$pdfPath}");

            return self::FAILURE;
        }

        $date = null;
        if (is_string($publicationDate) && $publicationDate !== '') {
            $date = Carbon::parse($publicationDate);
        }

        $uploadedFile = new UploadedFile(
            $pdfPath,
            basename($pdfPath),
            'application/pdf',
            null,
            true
        );

        $journal = $journalService->uploadAndCreate([
            'title' => $title,
            'publication_date' => $date?->toDateString(),
            'is_published' => $isPublished,
        ], $uploadedFile);

        if ($journal->number === null) {
            if (preg_match('/(?:^|\\D)(\\d{1,4})(?:\\D|$)/', basename($pdfPath), $m) === 1) {
                $journal->update(['number' => (string) $m[1]]);
            }
        }

        // Lancer l'extraction asynchrone (ou synchrone pour les tests si besoin)
        ProcessOfficialJournalExtraction::dispatch((string) $journal->id);

        $this->info("JO créé: {$journal->id}");
        $this->line("Titre: {$journal->title}");
        $this->line("PDF: {$journal->file_path}");
        $this->info('Extraction MinerU pour le JO lancée en arrière-plan.');

        return self::SUCCESS;
    }
}
