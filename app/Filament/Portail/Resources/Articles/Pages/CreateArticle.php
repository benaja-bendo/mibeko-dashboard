<?php

namespace App\Filament\Portail\Resources\Articles\Pages;

use App\Filament\Portail\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;
}
