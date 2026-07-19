<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\DossierGeneratedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierGeneratedDocument>
 */
class DossierGeneratedDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'template_id' => $this->faker->slug(2),
            'template_name' => $this->faker->sentence(3),
            'title' => $this->faker->sentence(4),
            'html' => '<p>'.$this->faker->paragraph().'</p>',
        ];
    }
}
