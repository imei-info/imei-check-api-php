<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

/**
 * Exception thrown when the account balance is insufficient to complete the request (HTTP 402).
 * Prompt the user to top up API credits on dash.imei.info.
 */
class ImeiInsufficientCreditsException extends ImeiException
{
}
