<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'device_id' => $this->faker->unique()->uuid(),
            'push_token' => 'fcm_'.$this->faker->unique()->sha256(),
            'platform' => $this->faker->randomElement(['android', 'ios']),
            // Défaut = le parc réel : l'app publiée sur les stores n'annonce pas
            // sa version, l'appareil reçoit donc le format hérité.
            'app_version' => null,
            'status' => 'active',
            'last_registered_at' => now(),
        ];
    }

    /**
     * Appareil dont l'app annonce sa version (à partir de la v1.2).
     */
    public function appVersion(?string $version): static
    {
        return $this->state(fn () => ['app_version' => $version]);
    }

    /**
     * Appareil rattaché à un utilisateur (session connectée).
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    /**
     * Jeton factice produit par le simulateur iOS (aucun envoi FCM ne peut aboutir).
     */
    public function simulated(): static
    {
        return $this->state(fn () => [
            'platform' => 'ios',
            'push_token' => Device::SIMULATED_TOKEN_PREFIX.'ios_'.$this->faker->unique()->numerify('##########'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
