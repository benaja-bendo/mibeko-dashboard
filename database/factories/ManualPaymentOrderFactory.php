<?php

namespace Database\Factories;

use App\Models\ManualPaymentOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ManualPaymentOrder>
 */
class ManualPaymentOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'reference' => 'MBK-'.now()->format('Ym').'-'.Str::upper((string) Str::ulid()),
            'offer_code' => 'pro',
            'amount_fcfa' => 15_000,
            'duration_months' => 1,
            'channel' => 'mobile_money',
            'payment_instructions' => 'Versez le montant au canal indiqué puis conservez la référence de transaction.',
            'status' => ManualPaymentOrder::STATUS_AWAITING_PAYMENT,
        ];
    }
}
