<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Les embeddings sont désactivés par défaut pendant l'ingestion
        // et seront générés par le job cron mibeko:process-rag

        // Call other seeders
        $this->call([
            SystemRequirementsSeeder::class,
            RolesAndPermissionsSeeder::class,
            // PopularCodesSeeder::class,
            // RealisticLegalSeeder::class, // Désactivé pour la prod : on utilise uniquement les vrais JSON
            // CongoJournalOfficielSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('🎉 Seeding principal terminé avec succès !');
        $this->command->warn('⚠️  ATTENTION: Les embeddings vectoriels n\'ont pas été générés pendant le seeding.');
        $this->command->warn('👉  Ils seront générés automatiquement par le job cron qui s\'exécute toutes les 10 minutes.');
        $this->command->warn('👉  Vous pouvez aussi les générer manuellement avec :');
        $this->command->info('    php artisan mibeko:process-rag --limit=200 --batch=20 --delay=500');
        $this->command->newLine();
    }
}
