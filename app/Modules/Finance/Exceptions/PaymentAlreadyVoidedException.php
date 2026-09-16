<?php

namespace App\Modules\Finance\Exceptions;

/**
 * Thrown when attempting to void an already-voided payment.
 * Phase 4 of Finance Architecture Redesign.
 */
class PaymentAlreadyVoidedException extends PaymentException
{
}
