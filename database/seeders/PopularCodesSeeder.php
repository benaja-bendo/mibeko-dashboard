<?php

namespace Database\Seeders;

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
                'ref' => 'republique-du-congo-constitution-2015',
            ],
        ];

        foreach ($codes as $data) {
            LegalDocument::firstOrCreate(
                ['reference_nor' => $data['ref']],
                [
                    'type_code' => $data['type'],
                    'titre_officiel' => $data['title'],
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
