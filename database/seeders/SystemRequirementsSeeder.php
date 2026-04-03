<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Institution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SystemRequirementsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('⚙️  Initialisation des données indispensables du système...');

        $this->seedDocumentTypes();
        $this->seedInstitutions();

        $this->command->info('✅ Données indispensables prêtes !');
    }

    private function seedDocumentTypes(): void
    {
        $types = [
            ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40],
            ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70],
            ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80],
            ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
            ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60],
            ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 50],
        ];

        foreach ($types as $type) {
            DocumentType::firstOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }

    private function seedInstitutions(): void
    {
        $institutions = [
            ['nom' => 'Présidence de la République', 'sigle' => 'PR'],
            ['nom' => 'Assemblée Nationale', 'sigle' => 'AN'],
            ['nom' => 'Sénat', 'sigle' => 'SEN'],
            ['nom' => 'Gouvernement', 'sigle' => 'GOUV'],
            ['nom' => 'Cour Constitutionnelle', 'sigle' => 'CC'],
            ['nom' => 'Cour Suprême', 'sigle' => 'CS'],
            ['nom' => 'Journal Officiel', 'sigle' => 'JO'],
        ];

        foreach ($institutions as $institution) {
            // Check if exists by name
            $exists = Institution::where('nom', $institution['nom'])->exists();
            if (! $exists) {
                Institution::create([
                    'id' => (string) Str::uuid(),
                    'nom' => $institution['nom'],
                    'sigle' => $institution['sigle'],
                ]);
            }
        }
    }
}
