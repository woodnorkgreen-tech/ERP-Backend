<?php

namespace App\Modules\Finance\Exceptions;

/**
 * Thrown when attempting to post to a closed accounting period.
 * Phase 4 of Finance Architecture Redesign.
 */
class ClosedPeriodException extends PaymentException
{
}
