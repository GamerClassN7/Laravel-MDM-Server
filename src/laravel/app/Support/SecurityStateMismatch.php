<?php

namespace App\Support;

use RuntimeException;

/** A delta of the security inventory does not follow the state the server has (or does not add up). */
class SecurityStateMismatch extends RuntimeException
{
    public function __construct(public readonly string $source, string $reason = 'does not follow the stored state')
    {
        parent::__construct("Security inventory source {$source} {$reason}.");
    }
}
