<?php

namespace App\Filament\Portail\Resources\LegalDocuments\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Components\Utilities\Get;
use App\Models\Institution;
use App\Models\DocumentType;
use App\Models\OfficialJournal;
use Illuminate\Support\HtmlString;

class LegalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Source')
                        ->description('Importer le PDF et choisir le mode de traitement.')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Grid::make(['default' => 1, 'xl' => 3])->schema([
                                Section::make('Fichier source')
                                    ->columnSpan(['default' => 1, 'xl' => 2])
                                    ->schema([
                                        FileUpload::make('file')
                                            ->label('Document PDF')
                                            ->acceptedFileTypes(['application/pdf'])
                                            ->disk('s3')
                                            ->directory('documents/pdfs')
                                            ->required(),

                                        Toggle::make('use_ocr')
                                            ->label('Extraction automatique (OCR/IA)')
                                            ->default(true)
                                            ->live()
                                            ->helperText('Recommandé : l’IA extrait la structure et pré-remplit les champs.'),
                                    ]),

                                Section::make('Statut')
                                    ->columnSpan(['default' => 1, 'xl' => 1])
                                    ->schema([
                                        Select::make('curation_status')
                                            ->label('Statut initial')
                                            ->options([
                                                'draft' => 'Brouillon',
                                                'review' => 'En révision',
                                            ])
                                            ->required()
                                            ->default('draft'),
                                    ]),
                            ]),
                        ]),

                    Step::make('Métadonnées')
                        ->description('Identifier le texte juridique (optionnel si l’extraction est activée).')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Section::make('Informations')
                                ->schema([
                                    TextInput::make('titre_officiel')
                                        ->label('Titre officiel')
                                        ->required(fn (Get $get): bool => ! (bool) $get('use_ocr'))
                                        ->maxLength(255),

                                    Grid::make(['default' => 1, 'md' => 2])->schema([
                                        Select::make('type_code')
                                            ->label('Type de document')
                                            ->options(DocumentType::pluck('nom', 'code'))
                                            ->required(fn (Get $get): bool => ! (bool) $get('use_ocr'))
                                            ->searchable(),

                                        Select::make('institution_id')
                                            ->label('Institution')
                                            ->options(Institution::pluck('nom', 'id'))
                                            ->required(fn (Get $get): bool => ! (bool) $get('use_ocr'))
                                            ->searchable(),

                                        Select::make('official_journal_id')
                                            ->label('Journal Officiel')
                                            ->options(OfficialJournal::pluck('title', 'id'))
                                            ->searchable(),

                                        TextInput::make('reference_nor')
                                            ->label('Référence NOR')
                                            ->maxLength(255),
                                    ]),

                                    Grid::make(['default' => 1, 'md' => 3])->schema([
                                        DatePicker::make('date_signature')
                                            ->label('Date de signature'),

                                        DatePicker::make('date_publication')
                                            ->label('Date de publication'),

                                        DatePicker::make('date_entree_vigueur')
                                            ->label('Date d’entrée en vigueur'),
                                    ]),
                                ]),
                        ]),

                    Step::make('Vérification')
                        ->description('Créer le document puis passer en curation.')
                        ->icon('heroicon-o-check-circle')
                        ->schema([
                            Section::make('Résumé')
                                ->schema([
                                    Text::make('Une fois créé, vous serez redirigé vers la workstation pour corriger la structure, réordonner et éditer les articles.')
                                ]),
                        ]),
                ])
                    ->contained(false)
                    ->columnSpanFull()
                    ->cancelAction(new HtmlString(
                        '<a class="fi-btn fi-btn-color-gray fi-btn-size-md" href="' . e(route('filament.portail.resources.legal-documents.index')) . '"><span class="fi-btn-label">Annuler</span></a>'
                    ))
                    ->submitAction(new HtmlString(
                        '<button type="submit" class="fi-btn fi-btn-color-primary fi-btn-size-md"><span class="fi-btn-label">Créer le document</span></button>'
                    ))
            ]);
    }
}
