<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\DossierPiece;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierPiece>
 */
class DossierPieceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'name' => $this->faker->words(2, true).'.pdf',
            'size' => $this->faker->numberBetween(1_000, 5_000_000),
            'mime' => 'application/pdf',
            'note' => $this->faker->optional()->sentence(),
            'added_at' => now(),
        ];
    }
}
