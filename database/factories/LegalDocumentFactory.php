<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
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
            'curation_status' => 'published',
            // Provenance par défaut (dashboard#119, garde-fou de publication) :
            // même doctrine que `date_entree_vigueur` ci-dessus — un document
            // de test « normal » satisfait le critère sans que chaque test de
            // publication ait à le poser explicitement. Les tests qui portent
            // spécifiquement sur une provenance absente l'écrasent à `null`.
            'metadata' => [
                'source_url' => $this->faker->url(),
                'fetched_at' => $this->faker->iso8601(),
                'autorite' => $this->faker->company(),
            ],
        ];
    }
}
