<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

/**
 * Exception thrown when the API request fails authorization (HTTP 401).
 * Typically occurs if the Bearer token is missing, invalid, or expired.
 */
class ImeiAuthenticationException extends ImeiException
{
}
