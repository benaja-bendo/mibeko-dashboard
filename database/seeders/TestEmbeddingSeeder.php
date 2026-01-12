<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TestEmbeddingSeeder extends CongoJournalOfficielSeeder
{
    /**
     * Run the database seeds.
     * Cette version de test ne traite qu'un seul fichier pour limiter les coûts et vérifier le RAG.
     */
    public function run(): void
    {
        $this->command->info('🧪 Démarrage du Test de Seeding (1 seul fichier)...');

        if (!File::isDirectory($this->jsonPath)) {
            $this->command->error("❌ Le dossier JSON est introuvable : {$this->jsonPath}");
            return;
        }

        $files = File::glob("{$this->jsonPath}/*.json");
        
        if (empty($files)) {
            $this->command->warn("⚠️ Aucun fichier JSON trouvé dans {$this->jsonPath}");
            return;
        }

        // On ne prend que le premier fichier pour le test
        $testFile = $files[0];
        $this->command->info("📦 Fichier de test sélectionné : " . basename($testFile));

        // Initialiser les types de documents
        $this->ensureDocumentTypesExist();

        // Traiter le fichier unique
        $this->processFile($testFile);

        $this->command->newLine();
        $this->command->info('✅ Test de seeding terminé !');
        $this->command->info('Vérifiez maintenant si les embeddings ont été générés dans la table article_versions.');
    }

    /**
     * Surcharge pour appeler la méthode privée de la classe parente via réflexion 
     * car ensureDocumentTypesExist est private dans CongoJournalOfficielSeeder.
     * Ou alors je la redéfinis ici pour plus de simplicité.
     */
    protected function ensureDocumentTypesExist(): void
    {
        $types = [
            ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40],
            ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70],
            ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80],
            ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
            ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60],
        ];

        foreach ($types as $type) {
            \App\Models\DocumentType::firstOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }

    /**
     * Surcharge pour appeler la méthode privée processFile via réflexion.
     */
    protected function processFile(string $jsonFilePath): void
    {
        // On utilise la réflexion car la méthode est private dans le parent
        $reflection = new \ReflectionClass(parent::class);
        $method = $reflection->getMethod('processFile');
        $method->setAccessible(true);
        $method->invoke($this, $jsonFilePath);
    }
}
