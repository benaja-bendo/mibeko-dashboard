<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Database\Seeder;

class PopularCodesSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            [
                'title' => 'Code Civil',
                'type' => 'CODE', // Assuming CODE exists or we map to LOI/other
                'ref' => 'CODE-CIVIL',
            ],
            [
                'title' => 'Code Pénal',
                'type' => 'CODE',
                'ref' => 'CODE-PENAL',
            ],
            [
                'title' => 'Code de la Famille',
                'type' => 'CODE',
                'ref' => 'CODE-FAMILLE',
            ],
            [
                'title' => 'Code du Travail',
                'type' => 'CODE',
                'ref' => 'CODE-TRAVAIL',
            ],
            [
                'title' => 'Constitution de la République du Congo',
                'type' => 'CONST',
                'ref' => 'CONST-2015',
            ],
        ];

        // Ensure CODE and CONST type exists if we use it, otherwise fallback
        DocumentType::firstOrCreate(
            ['code' => 'CODE'],
            ['nom' => 'Code', 'niveau_hierarchique' => 50]
        );

        DocumentType::firstOrCreate(
            ['code' => 'CONST'],
            ['nom' => 'Constitution', 'niveau_hierarchique' => 10]
        );

        foreach ($codes as $data) {
            LegalDocument::firstOrCreate(
                ['titre_officiel' => $data['title']],
                [
                    'type_code' => $data['type'],
                    'reference_nor' => $data['ref'],
                    'statut' => 'vigueur',
                    'curation_status' => 'published', // Pre-published
                    'date_publication' => now(),
                    'date_signature' => now(),
                ]
            );
        }
    }
}
