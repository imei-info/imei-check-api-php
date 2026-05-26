<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

/**
 * Exception thrown when the input data fails server-side validation (HTTP 422).
 * e.g., the IMEI does not pass the Luhn algorithm checksum.
 */
class ImeiValidationException extends ImeiException
{
}
