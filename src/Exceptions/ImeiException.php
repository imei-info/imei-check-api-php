<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck\Exceptions;

use Exception;

/**
 * Base exception class for all IMEI.info SDK errors.
 */
class ImeiException extends Exception
{
    /** @var int|null */
    protected $statusCode;
    
    /** @var string|null */
    protected $errorCode;
    
    /** @var string|null */
    protected $rawResponse;

    public function __construct(
        string $message = "",
        int $code = 0,
        ?int $statusCode = null,
        ?string $errorCode = null,
        ?string $rawResponse = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->rawResponse = $rawResponse;
    }

    /**
     * Get the HTTP status code returned by the API.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Get the structured error code returned in the JSON response (e.g. 'insufficient_credits').
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get the raw HTTP response body.
     */
    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }
}
