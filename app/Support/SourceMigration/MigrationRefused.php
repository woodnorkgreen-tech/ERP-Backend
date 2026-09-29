<?php

namespace App\Support\SourceMigration;

use RuntimeException;

/** A safety guard refused the operation. The message says which guard and why. */
class MigrationRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public static function because(array $reasons): self
    {
        return new self(implode("\n", $reasons));
    }
}
