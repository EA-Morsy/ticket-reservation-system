<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\FakePaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Exercises the webhook HTTP flow with a signed fake payment notification.
 */
class PaymentSimulate extends Command
{
    protected $signature = 'payments:simulate {outcome : success or failed} {reference : Payment reference}';

    protected $description = 'Deliver a signed fake payment notification to the webhook endpoint.';

    public function handle(Kernel $kernel, FakePaymentGateway $fakePaymentGateway): int
    {
        if (config('payment.driver') !== 'fake') {
            $this->error('Payment simulation is only available with PAYMENT_DRIVER=fake.');

            return self::FAILURE;
        }

        $outcome = $this->argument('outcome');

        if (! in_array($outcome, ['success', 'failed', 'failure'], true)) {
            $this->error('Outcome must be success, failed, or failure.');

            return self::INVALID;
        }

        $outcome = $outcome === 'failure' ? 'failed' : $outcome;

        $payment = Payment::query()->where('reference', $this->argument('reference'))->first();

        if ($payment === null) {
            $this->error('Payment reference was not found.');

            return self::FAILURE;
        }

        $payload = $fakePaymentGateway->notificationPayload(
            $payment->reference,
            $outcome,
            $payment->amount_minor,
            $payment->currency,
        );
        $request = Request::create(
            '/api/payments/webhook',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request);

        $this->line("Webhook responded with {$response->getStatusCode()}.");

        return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
