<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type_code' => DocumentType::factory(),
            'institution_id' => Institution::factory(),
            'titre_officiel' => $this->faker->sentence(),
            'reference_nor' => $this->faker->bothify('NOR-####-??'),
            'date_signature' => $this->faker->date(),
            'date_publication' => $this->faker->date(),
            'date_entree_vigueur' => $this->faker->date(),
            'statut' => $this->faker->randomElement(['vigueur', 'abroge', 'projet']),
        ];
    }
}
