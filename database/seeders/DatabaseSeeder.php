<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Observers\ArticleVersionObserver;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Disable automatic embedding generation during seeding
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

        // Call other seeders
        $this->call([
            PopularCodesSeeder::class,
            // RealisticLegalSeeder::class, // Désactivé pour la prod : on utilise uniquement les vrais JSON
            CongoJournalOfficielSeeder::class,
        ]);

        // Re-enable it if needed (optional since seeder process ends here)
        ArticleVersionObserver::$shouldSkipEmbeddings = false;
    }
}
