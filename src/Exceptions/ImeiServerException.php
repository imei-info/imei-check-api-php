<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

/**
 * Exception thrown when the IMEI.info API servers return a server-side error (HTTP 500+).
 */
class ImeiServerException extends ImeiException
{
}
