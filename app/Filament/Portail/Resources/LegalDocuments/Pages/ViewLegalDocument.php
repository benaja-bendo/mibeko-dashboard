<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Pages;

use App\Filament\Portail\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLegalDocument extends ViewRecord
{
    protected static string $resource = LegalDocumentResource::class;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        $this->redirect(
            static::getResource()::getUrl('workstation', ['record' => $this->getRecord()]),
            navigate: true,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
