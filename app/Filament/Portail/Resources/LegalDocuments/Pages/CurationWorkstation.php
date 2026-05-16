<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Pages;

use App\Filament\Portail\Resources\LegalDocuments\LegalDocumentResource;
use App\Models\Article;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class CurationWorkstation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LegalDocumentResource::class;

    protected string $view = 'filament.portail.resources.legal-documents.pages.curation-workstation';

    protected static string $layout = 'filament.portail.layouts.curation';

    public ?string $selectedNodeId = null;
    public ?string $selectedArticleId = null;
    public ?string $selectedArticleLabel = null;
    public ?string $selectedArticleStatus = null;

    public bool $isPdfAvailable = false;

    public ?string $pdfUrl = null;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->loadMissing(['mediaFiles']);

        $pdf = $this->record->mediaFiles->firstWhere('mime_type', 'application/pdf')
            ?? $this->record->mediaFiles->first(fn ($file) => str_ends_with(strtolower((string) $file->file_path), '.pdf'));
        $path = $pdf?->file_path;
        if (blank($path)) {
            return;
        }

        $diskName = str_starts_with($path, 'documents/')
            ? 's3'
            : config('filesystems.default', 'local');

        try {
            $this->isPdfAvailable = Storage::disk($diskName)->exists($path);
        } catch (\Throwable) {
            $this->isPdfAvailable = false;
        }

        if ($this->isPdfAvailable) {
            $this->pdfUrl = route('pdf.proxy', ['id' => $this->record->id]);
        }
    }

    #[On('articleSelected')]
    public function setSelectedArticleId(string $articleId): void
    {
        $this->selectedArticleId = $articleId;

        $article = Article::query()
            ->where('document_id', $this->record->id)
            ->whereKey($articleId)
            ->first();

        $this->selectedArticleLabel = $article?->numero_article ? ('Article ' . $article->numero_article) : null;
        $this->selectedArticleStatus = $article?->validation_status;
    }

    public function getTitle(): string
    {
        return 'Curation : ' . $this->record->titre_officiel;
    }
}
