<?php

declare(strict_types=1);

namespace RedisSimply;

use RuntimeException;

/**
 * A failure whose message is safe, and meant, to be shown to the user.
 */
class UserError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
