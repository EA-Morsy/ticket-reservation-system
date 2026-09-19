<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'provider' => 'fake',
            'reference' => 'pay_'.Str::lower(fake()->unique()->bothify('??????')),
            'provider_transaction_id' => null,
            'amount_minor' => 15000,
            'currency' => 'EGP',
            'status' => 'pending',
            'paid_at' => null,
        ];
    }
}
