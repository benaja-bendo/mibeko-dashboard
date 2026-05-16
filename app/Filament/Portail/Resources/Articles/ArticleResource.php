<?php

namespace App\Filament\Portail\Resources\Articles;

use App\Filament\Portail\Resources\Articles\Pages\CreateArticle;
use App\Filament\Portail\Resources\Articles\Pages\EditArticle;
use App\Filament\Portail\Resources\Articles\Pages\ListArticles;
use App\Filament\Portail\Resources\Articles\Pages\ViewArticle;
use App\Filament\Portail\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Portail\Resources\Articles\Schemas\ArticleInfolist;
use App\Filament\Portail\Resources\Articles\Tables\ArticlesTable;
use App\Models\Article;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Curation';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ArticleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
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
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'view' => ViewArticle::route('/{record}'),
            'edit' => EditArticle::route('/{record}/edit'),
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
