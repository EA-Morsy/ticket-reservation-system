<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the configured payment strategy through Laravel's container.
 */
final class PaymentGatewayFactory
{
    public function __construct(
        private Repository $config,
        private Container $container,
    ) {}

    public function make(?string $driver = null): PaymentGateway
    {
        $driver ??= $this->config->get('payment.driver');
        $drivers = $this->config->get('payment.drivers', []);
        $gatewayClass = is_string($driver) && is_array($drivers) ? ($drivers[$driver] ?? null) : null;

        if (! is_string($gatewayClass) || ! is_a($gatewayClass, PaymentGateway::class, true)) {
            throw new InvalidArgumentException('Unsupported payment driver.');
        }

        return $this->container->make($gatewayClass);
    }
}
