<?php

namespace Database\Factories;

use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentControleRun>
 */
class DocumentControleRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => LegalDocument::factory(),
            'version_jeu' => 'v3',
            'date' => now(),
            'resultats' => [],
            'resultat' => DocumentControleRun::RESULTAT_OK,
        ];
    }
}
