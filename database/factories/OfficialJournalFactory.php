<?php

namespace Database\Factories;

use App\Models\OfficialJournal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialJournal>
 */
class OfficialJournalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Journal Officiel n° '.$this->faker->numberBetween(1, 100),
            'publication_date' => $this->faker->date(),
            'file_path' => 'official_journals/'.$this->faker->uuid().'.pdf',
            'transcription_status' => OfficialJournal::STATUS_PENDING,
            'is_published' => true,
        ];
    }
}
