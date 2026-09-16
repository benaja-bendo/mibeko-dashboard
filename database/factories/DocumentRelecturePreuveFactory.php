<?php

namespace Database\Factories;

use App\Models\DocumentControleRun;
use App\Models\DocumentRelecturePreuve;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRelecturePreuve>
 */
class DocumentRelecturePreuveFactory extends Factory
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
            'actor_id' => User::factory(),
            'document_controle_run_id' => DocumentControleRun::factory(),
            'points_vus' => [],
            'sondage_articles' => [],
            'sondage_confirmes' => [],
        ];
    }
}
