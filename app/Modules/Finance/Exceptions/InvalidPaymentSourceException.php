<?php

namespace App\Modules\Finance\Exceptions;

/**
 * Thrown when attempting to use an invalid or non-payment-capable source.
 * Phase 4 of Finance Architecture Redesign.
 */
class InvalidPaymentSourceException extends PaymentException
{
}
