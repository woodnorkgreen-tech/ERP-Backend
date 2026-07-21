<?php

namespace App\Exceptions;

use Exception;

/**
 * A deliberate, user-facing workflow rejection (e.g. missing prerequisite data,
 * an out-of-order task completion). Distinguishes intentional business-rule
 * rejections from a plain \Exception that leaked from somewhere unexpected.
 */
class WorkflowValidationException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message, 422);
    }
}
