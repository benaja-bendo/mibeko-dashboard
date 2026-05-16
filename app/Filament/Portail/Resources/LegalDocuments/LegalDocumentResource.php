<?php

namespace App\Filament\Portail\Resources\LegalDocuments;

use App\Filament\Portail\Resources\LegalDocuments\Pages\CreateLegalDocument;
use App\Filament\Portail\Resources\LegalDocuments\Pages\EditLegalDocument;
use App\Filament\Portail\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Filament\Portail\Resources\LegalDocuments\Pages\ViewLegalDocument;
use App\Filament\Portail\Resources\LegalDocuments\Schemas\LegalDocumentForm;
use App\Filament\Portail\Resources\LegalDocuments\Schemas\LegalDocumentInfolist;
use App\Filament\Portail\Resources\LegalDocuments\Tables\LegalDocumentsTable;
use App\Models\LegalDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LegalDocumentResource extends Resource
{
    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Curation';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Documents Juridiques';
    }

    public static function form(Schema $schema): Schema
    {
        return LegalDocumentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LegalDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LegalDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
            'create' => CreateLegalDocument::route('/create'),
            'view' => ViewLegalDocument::route('/{record}'),
            'edit' => EditLegalDocument::route('/{record}/edit'),
            'workstation' => Pages\CurationWorkstation::route('/{record}/workstation'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
