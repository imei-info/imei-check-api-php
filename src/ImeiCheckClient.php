<?php

declare(strict_types=1);

namespace ImeiInfo\ImeiCheck;

use ImeiInfo\ImeiCheck\Exceptions\ImeiException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiAuthenticationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiInsufficientCreditsException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiValidationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiRateLimitException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiServerException;

/**
 * Official B2B Client for the IMEI.info API v5.
 * 
 * Built with native PHP cURL to prevent dependency version conflicts (dependency hell)
 * in WordPress, WooCommerce, and wholesale platforms like Dhru Fusion.
 */
class ImeiCheckClient
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var float */
    private $timeout;

    /**
     * @param string $apiKey Secure Bearer Token from dash.imei.info.
     * @param string|null $baseUrl Target API URL. Defaults to the official IMEI.info v5 Production API.
     * @param float $timeout Timeout in seconds for API calls. Default is 15.0 seconds.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        float $timeout = 15.0
    ) {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl ?? 'https://dash.imei.info', '/');
        $this->timeout = $timeout;
    }

    /**
     * Performs a complete technical and blacklist check for a 15-digit IMEI number.
     *
     * Always uses the /api/check/ endpoint. When $sync is true, the method blocks
     * and polls /api/search_history/ until the result is ready (up to $syncTimeout
     * seconds), returning the final result in a single call.
     *
     * NOTE: The /api-sync/ endpoint exists in the dashboard but only accepts
     * session-based cookies (browser login), not Bearer tokens. All programmatic
     * access must go through /api/.
     *
     * @param string $imei          The 15-digit IMEI number.
     * @param int    $serviceId     The Service ID to use for the check. Default is 0.
     * @param bool   $sync          True = block and wait for result (server-side polling).
     *                              False = return immediately with 202/pending data.
     * @param int    $syncTimeout   Max seconds to wait in sync mode. Default 25.
     * @return array Technical specifications, carrier lock, and blacklist details.
     *
     * @throws ImeiAuthenticationException      If the API key is invalid (HTTP 401).
     * @throws ImeiInsufficientCreditsException If account credits are depleted (HTTP 402).
     * @throws ImeiValidationException          If IMEI format validation fails (HTTP 422).
     * @throws ImeiRateLimitException           If rate limits are exceeded (HTTP 429).
     * @throws ImeiServerException              If a server-side error occurs (HTTP 5xx).
     * @throws ImeiException                    For network errors or unexpected responses.
     */
    public function checkImei(string $imei, int $serviceId = 0, bool $sync = false, int $syncTimeout = 25): array
    {
        $imei = trim($imei);
        $pathPrefix = $sync ? '/api-sync/check/' : '/api/check/';

        // Important: the API requires API_KEY to be the first query parameter in the query string
        $queryParams = [
            'API_KEY' => $this->apiKey,
            'format' => 'json',
            'imei' => $imei,
        ];
        $url = $this->baseUrl . $pathPrefix . $serviceId . '/?' . http_build_query($queryParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)($this->timeout * 1000));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($response === false) {
            throw new ImeiException(
                'Network connection failure: ' . $curlError,
                $curlErrno
            );
        }

        // Detect application-level auth errors hidden behind HTTP 200
        // (e.g. a proxy that wraps error responses in a 200 envelope)
        if ($httpCode === 200) {
            $probe = json_decode($response, true);
            if (is_array($probe) && isset($probe['detail']) && stripos((string)$probe['detail'], 'token') !== false) {
                throw new ImeiAuthenticationException(
                    'Authorization Failed: ' . $probe['detail'],
                    401, 401, 'invalid_token', $response
                );
            }
        }

        if ($httpCode === 200 || $httpCode === 202) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ImeiException(
                    'Invalid JSON response from API: ' . json_last_error_msg(),
                    $httpCode, $httpCode, 'invalid_json_format', $response
                );
            }

            $data['http_code'] = $httpCode;

            // Async queue response (202 or explicit pending status)
            $isPending = ($httpCode === 202)
                || (isset($data['status']) && in_array(strtolower((string)$data['status']), ['pending', 'processing', 'running'], true));

            if (!$isPending) {
                // Already done on first call (rare for some instant services)
                return $data;
            }

            // Extract history_id so we can poll
            $historyId = (int)($data['history_id'] ?? $data['id'] ?? 0);

            if (!$sync || $historyId === 0) {
                // Caller wants async flow — return pending data so JS can poll
                if (!isset($data['status'])) {
                    $data['status'] = 'Pending';
                }
                return $data;
            }

            // --- Server-side blocking poll ---
            return $this->pollUntilDone($historyId, $syncTimeout);
        }

        $this->handleResponseError($httpCode, $response);

        throw new ImeiException(
            'Unexpected API response with code: ' . $httpCode,
            $httpCode, $httpCode, 'unexpected_http_code', $response
        );
    }

    /**
     * Blocks and polls /api/search_history/{id}/ until the result status is
     * "Done" or the timeout is reached.
     *
     * @param int $historyId  The history_id from the initial 202/pending response.
     * @param int $maxSeconds Maximum seconds to wait before giving up.
     * @return array          The completed result record.
     *
     * @throws ImeiException  If the timeout is reached or an error occurs during polling.
     */
    public function pollUntilDone(int $historyId, int $maxSeconds = 25): array
    {
        $deadline    = time() + $maxSeconds;
        $pollInterval = 2; // seconds between polls

        do {
            sleep($pollInterval);

            $data = $this->getSearchResult($historyId);
            $status = strtolower((string)($data['status'] ?? ''));

            if ($status === 'done' || $status === 'completed') {
                $data['http_code'] = 200;
                return $data;
            }

            if (in_array($status, ['error', 'failed', 'cancelled'], true)) {
                throw new ImeiException(
                    'Service returned error status: ' . ($data['status'] ?? 'unknown'),
                    0, 0, 'service_error', json_encode($data)
                );
            }

        } while (time() < $deadline);

        throw new ImeiException(
            "Sync timeout: result for history_id={$historyId} was not ready within {$maxSeconds}s. "
            . 'Use async (Queue) mode or increase the timeout.',
            408, 408, 'sync_timeout', ''
        );
    }

    /**
     * Retrieves the status and result of a queued (asynchronous) IMEI check request.
     * 
     * @param int $historyId The history_id returned in the 202 response.
     * @return array The complete search history record containing status, service info, and the 'result' specifications block.
     * 
     * @throws ImeiAuthenticationException If the API key is invalid (HTTP 401).
     * @throws ImeiException For network errors or if the record is not found (HTTP 404).
     */
    public function getSearchResult(int $historyId): array
    {
        $queryParams = [
            'API_KEY' => $this->apiKey,
            'format' => 'json',
        ];
        $url = $this->baseUrl . '/api/search_history/' . $historyId . '/?' . http_build_query($queryParams);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)($this->timeout * 1000));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        curl_close($ch);

        if ($response === false) {
            throw new ImeiException("Network connection failure: " . $curlError, $curlErrno);
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ImeiException("Invalid JSON response returned from API: " . json_last_error_msg(), $httpCode, $httpCode, 'invalid_json_format', $response);
            }
            return $data;
        }

        $this->handleResponseError($httpCode, $response);

        throw new ImeiException("Unexpected API response with code: " . $httpCode, $httpCode, $httpCode, 'unexpected_http_code', $response);
    }

    /**
     * Parses the HTTP error code and maps it to a structured custom exception.
     * 
     * @param int $httpCode The HTTP status code.
     * @param string $response The raw HTTP response body.
     * 
     * @throws ImeiAuthenticationException
     * @throws ImeiInsufficientCreditsException
     * @throws ImeiValidationException
     * @throws ImeiRateLimitException
     * @throws ImeiServerException
     * @throws ImeiException
     */
    private function handleResponseError(int $httpCode, string $response): void
    {
        $message = "An error occurred while calling the IMEI.info API.";
        $errorCode = 'unknown_error';
        $errorType = 'Error';

        // Try to parse structured JSON error response
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $message = $data['message'] ?? $message;
            $errorCode = $data['code'] ?? $errorCode;
            $errorType = $data['error'] ?? $errorType;
        }

        switch ($httpCode) {
            case 401:
                throw new ImeiAuthenticationException($message, $httpCode, $httpCode, $errorCode, $response);
            case 402:
                throw new ImeiInsufficientCreditsException($message, $httpCode, $httpCode, $errorCode, $response);
            case 422:
                throw new ImeiValidationException($message, $httpCode, $httpCode, $errorCode, $response);
            case 429:
                throw new ImeiRateLimitException($message, $httpCode, $httpCode, $errorCode, $response);
            case 500:
            case 502:
            case 503:
            case 504:
                throw new ImeiServerException("Server Error (" . $httpCode . "): " . $message, $httpCode, $httpCode, $errorCode, $response);
            default:
                throw new ImeiException($message, $httpCode, $httpCode, $errorCode, $response);
        }
    }
}
