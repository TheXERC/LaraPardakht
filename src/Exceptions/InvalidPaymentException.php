<?php

declare(strict_types=1);

namespace LaraPardakht\Exceptions;

/**
 * Thrown when a payment verification fails (payment was not successful).
 */
class InvalidPaymentException extends GatewayException {}
