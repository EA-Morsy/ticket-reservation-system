<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSimulateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_driver_command_delivers_a_signed_webhook(): void
    {
        $event = Event::factory()->create();
        $reservation = Reservation::factory()->for(User::factory())->for($event)->create(['total_minor' => 15000]);
        $reservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));
        $payment = Payment::factory()->for($reservation)->create(['amount_minor' => 15000]);

        $this->artisan('payments:simulate success '.$payment->reference)
            ->expectsOutput('Webhook responded with 200.')
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'completed']);
    }

    public function test_command_refuses_non_fake_driver(): void
    {
        config()->set('payment.driver', 'paymob');

        $this->artisan('payments:simulate success missing')
            ->expectsOutput('Payment simulation is only available with PAYMENT_DRIVER=fake.')
            ->assertFailed();
    }
}
