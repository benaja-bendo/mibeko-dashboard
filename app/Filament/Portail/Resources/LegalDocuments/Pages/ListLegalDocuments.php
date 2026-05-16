<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Pages;

use App\Filament\Portail\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegalDocuments extends ListRecords
{
    protected static string $resource = LegalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
