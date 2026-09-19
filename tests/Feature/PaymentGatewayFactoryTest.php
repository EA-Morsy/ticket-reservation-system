<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymobGateway;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PaymentGatewayFactoryTest extends TestCase
{
    #[TestWith(['fake', FakePaymentGateway::class])]
    #[TestWith(['paymob', PaymobGateway::class])]
    public function test_contract_resolves_the_configured_gateway_without_network_calls(string $driver, string $expectedClass): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('payment.driver', $driver);

        $this->assertInstanceOf($expectedClass, $this->app->make(PaymentGateway::class));
        Http::assertNothingSent();
    }

    public function test_explicit_driver_does_not_change_the_default_gateway(): void
    {
        config()->set('payment.driver', 'fake');

        $gateway = $this->app->make(PaymentGatewayFactory::class)->make('paymob');

        $this->assertInstanceOf(PaymobGateway::class, $gateway);
        $this->assertInstanceOf(FakePaymentGateway::class, $this->app->make(PaymentGateway::class));
    }

    public function test_additional_gateway_registration_uses_container_dependencies(): void
    {
        config()->set('payment.drivers.custom', FakePaymentGateway::class);
        config()->set('payment.driver', 'custom');
        $gateway = new FakePaymentGateway;
        $this->app->instance(FakePaymentGateway::class, $gateway);

        $this->assertSame($gateway, $this->app->make(PaymentGateway::class));
    }

    #[TestWith([null])]
    #[TestWith([\stdClass::class])]
    #[TestWith(['NonexistentPaymentGateway'])]
    public function test_unknown_or_invalid_registration_is_rejected(?string $gatewayClass): void
    {
        config()->set('payment.driver', 'invalid');
        config()->set('payment.drivers.invalid', $gatewayClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment driver.');

        $this->app->make(PaymentGateway::class);
    }
}
