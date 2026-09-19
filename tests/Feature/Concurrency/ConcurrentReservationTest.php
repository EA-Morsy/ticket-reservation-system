<?php

namespace Tests\Feature\Concurrency;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Payments\FakePaymentGateway;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentReservationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_seat_race_has_exactly_one_winner_and_no_partial_loser(): void
    {
        $event = Event::factory()->create();
        Seat::factory()->for($event)->create(['number' => 'A1']);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $responses = $this->runConcurrently([
            $this->reservationJob($firstUser, $event, ['A1']),
            $this->reservationJob($secondUser, $event, ['A1']),
        ]);

        $this->assertSame([201, 409], $this->statuses($responses));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_seat', 1);
    }

    public function test_overlapping_multi_seat_race_remains_all_or_nothing(): void
    {
        $event = Event::factory()->create();
        foreach (['A1', 'A2', 'A3'] as $number) {
            Seat::factory()->for($event)->create(['number' => $number]);
        }
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $responses = $this->runConcurrently([
            $this->reservationJob($firstUser, $event, ['A1', 'A2']),
            $this->reservationJob($secondUser, $event, ['A2', 'A3']),
        ]);

        $this->assertSame([201, 409], $this->statuses($responses));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_seat', 2);
    }

    public function test_late_webhook_and_new_reservation_race_allocate_a_shared_seat_exclusively(): void
    {
        $event = Event::factory()->create(['seat_price_minor' => 15000]);
        $seat = Seat::factory()->for($event)->create(['number' => 'A1']);
        $owner = User::factory()->create();
        $newUser = User::factory()->create();
        $expiredReservation = Reservation::factory()->for($owner)->for($event)->create([
            'total_minor' => 15000,
            'expires_at' => now()->subSecond(),
        ]);
        $expiredReservation->seats()->attach($seat);
        $payment = Payment::factory()->for($expiredReservation)->create(['amount_minor' => 15000]);
        $payload = app(FakePaymentGateway::class)->notificationPayload($payment->reference, 'success', 15000, 'EGP');

        $responses = $this->runConcurrently([
            ['path' => '/api/payments/webhook', 'payload' => $payload],
            $this->reservationJob($newUser, $event, ['A1']),
        ]);

        $this->assertSame(200, $this->statuses($responses)[0]);
        $this->assertContains($this->statuses($responses)[1], [201, 409]);
        $this->assertSame(1, Reservation::query()->where('status', 'completed')->count() + Reservation::query()->where('status', 'pending')->where('expires_at', '>', now())->count());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);

        $duplicateResponses = $this->runConcurrently([
            ['path' => '/api/payments/webhook', 'payload' => $payload],
            ['path' => '/api/payments/webhook', 'payload' => $payload],
        ]);
        $this->assertSame([200, 200], $this->statuses($duplicateResponses));
    }

    /** @param array<int, array{path: string, payload: array<string, mixed>, token?: string}> $jobs
     * @return array<int, array{status: int, body: array<string, mixed>|null}>
     */
    private function runConcurrently(array $jobs): array
    {
        $processes = collect($jobs)->map(function (array $job): Process {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Feature/Concurrency/worker.php'),
                base64_encode(json_encode($job, JSON_THROW_ON_ERROR)),
            ], base_path(), ['APP_ENV' => 'testing']);
            $process->start();

            return $process;
        });

        return $processes->map(function (Process $process): array {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        })->all();
    }

    /** @param array<int, array{status: int, body: array<string, mixed>|null}> $responses
     * @return array<int, int>
     */
    private function statuses(array $responses): array
    {
        return collect($responses)->pluck('status')->sort()->values()->all();
    }

    /** @param array<int, string> $seatNumbers
     * @return array{path: string, payload: array{event_id: int, seat_numbers: array<int, string>}, token: string}
     */
    private function reservationJob(User $user, Event $event, array $seatNumbers): array
    {
        return [
            'path' => '/api/reservations',
            'payload' => ['event_id' => $event->id, 'seat_numbers' => $seatNumbers],
            'token' => $user->createToken('concurrency')->plainTextToken,
        ];
    }
}
