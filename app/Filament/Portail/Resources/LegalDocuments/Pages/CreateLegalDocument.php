<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Pages;

use App\Filament\Portail\Resources\LegalDocuments\LegalDocumentResource;
use App\Services\RabbitMQService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Log;

class CreateLegalDocument extends CreateRecord
{
    protected static string $resource = LegalDocumentResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;

    public ?string $uploadedPdfPath = null;

    public bool $useOcr = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->useOcr = (bool) ($data['use_ocr'] ?? true);

        $file = $data['file'] ?? null;
        if (is_array($file)) {
            $file = $file[0] ?? null;
        }

        $this->uploadedPdfPath = is_string($file) ? $file : null;

        unset($data['file'], $data['use_ocr']);

        if ($this->useOcr && filled($this->uploadedPdfPath)) {
            $data['extraction_status'] = 'processing';
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (blank($this->uploadedPdfPath)) {
            return;
        }

        $this->record->mediaFiles()->create([
            'file_path' => $this->uploadedPdfPath,
            'mime_type' => 'application/pdf',
            'description' => 'Original importé',
        ]);

        if (! $this->useOcr) {
            return;
        }

        try {
            app(RabbitMQService::class)->publish('pdf_extraction_tasks', [
                'task_id' => (string) $this->record->id,
                'filename' => $this->uploadedPdfPath,
            ]);
        } catch (\Throwable $e) {
            Log::error('Échec de publication RabbitMQ (pdf_extraction_tasks): ' . $e->getMessage());
        }
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('workstation', ['record' => $this->getRecord()]);
    }
}
