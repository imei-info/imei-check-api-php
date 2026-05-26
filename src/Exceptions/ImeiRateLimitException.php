<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

/**
 * Exception thrown when the API rate limit has been exceeded (HTTP 429).
 */
class ImeiRateLimitException extends ImeiException
{
}
