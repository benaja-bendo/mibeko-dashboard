<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class LegalDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('titre_officiel')
                    ->label('Titre Officiel')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('type.nom')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('date_publication')
                    ->label('Publication')
                    ->date()
                    ->sortable(),
                TextColumn::make('curation_status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'review' => 'primary',
                        'validated', 'published' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
