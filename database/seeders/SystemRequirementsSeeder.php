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
            ['code' => 'AU', 'nom' => 'Acte uniforme OHADA', 'niveau_hierarchique' => 15],
            ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40],
            ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70],
            ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80],
            ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
            ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60],
            ['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 90],
            ['code' => 'CONV', 'nom' => 'Convention collective', 'niveau_hierarchique' => 85],
            ['code' => 'DOCT', 'nom' => 'Doctrine / Ouvrage', 'niveau_hierarchique' => 120],
            ['code' => 'TEXTE', 'nom' => 'Texte Juridique (Générique)', 'niveau_hierarchique' => 100],
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
            Institution::firstOrCreate(
                ['nom' => $institution['nom']],
                [
                    'id' => (string) Str::uuid(),
                    'sigle' => $institution['sigle'],
                ]
            );
        }
    }
}
