<?php

namespace Database\Seeders;

use App\Observers\ArticleVersionObserver;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Disable automatic embedding generation during seeding (pour aller plus vite)
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

        // Call other seeders
        $this->call([
            SystemRequirementsSeeder::class,
            // PopularCodesSeeder::class,
            // RealisticLegalSeeder::class, // Désactivé pour la prod : on utilise uniquement les vrais JSON
            // CongoJournalOfficielSeeder::class,
        ]);

        // Re-enable it if needed (optional since seeder process ends here)
        ArticleVersionObserver::$shouldSkipEmbeddings = false;

        $this->command->newLine();
        $this->command->info('🎉 Seeding principal terminé avec succès !');
        $this->command->warn('⚠️  ATTENTION: Les embeddings vectoriels n\'ont pas été générés pour gagner du temps.');
        $this->command->warn('👉  Veuillez exécuter la commande suivante pour les générer en arrière-plan :');
        $this->command->info('    php artisan mibeko:process-rag --limit=200 --batch=20 --delay=500');
        $this->command->newLine();
    }
}
