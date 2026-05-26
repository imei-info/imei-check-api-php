<?php

declare(strict_types=1);

// Enable full error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// Allow enough execution time for server-side sync polling (up to ~30 s)
set_time_limit(60);

// 1. Try loading Composer Autoloader. Fallback to manual require.
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    require_once __DIR__ . '/../src/Exceptions/ImeiException.php';
    require_once __DIR__ . '/../src/Exceptions/ImeiAuthenticationException.php';
    require_once __DIR__ . '/../src/Exceptions/ImeiInsufficientCreditsException.php';
    require_once __DIR__ . '/../src/Exceptions/ImeiValidationException.php';
    require_once __DIR__ . '/../src/Exceptions/ImeiRateLimitException.php';
    require_once __DIR__ . '/../src/Exceptions/ImeiServerException.php';
    require_once __DIR__ . '/../src/ImeiCheckClient.php';
}

use ImeiInfo\ImeiCheck\ImeiCheckClient;
use ImeiInfo\ImeiCheck\Exceptions\ImeiAuthenticationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiInsufficientCreditsException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiValidationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiException;

// Endpoint for dynamic service synchronization (Proxy to dash.imei.info API to bypass CORS)
if (isset($_GET['action']) && $_GET['action'] === 'get_services') {
    header('Content-Type: application/json');
    $key = $_GET['api_key'] ?? '';
    $gateway = $_GET['base_url'] ?? 'mock_php';

    if (empty($key) && $gateway !== 'mock_php') {
        echo json_encode(['error' => 'API Key is required to synchronize services.']);
        exit;
    }

    if ($gateway === 'mock_php') {
        // Return simulated service list locally
        $mockServices = [
            [
                "id" => 0,
                "name" => "Basic IMEI Check",
                "price" => "Token based"
            ],
            [
                "id" => 86,
                "name" => "GENERIC: Xiaomi Mi Lock Info Check",
                "price" => 0.02
            ],
            [
                "id" => 72,
                "name" => "GENERIC: Oppo Info Check",
                "price" => 0.60
            ],
            [
                "id" => 120,
                "name" => "APPLE: iPhone Carrier & Blacklist Info",
                "price" => 0.15
            ]
        ];
        echo json_encode($mockServices);
        exit;
    } else {
        // Production API Call to fetch available services
        $queryParams = [
            'API_KEY' => $key,
            'format' => 'json',
        ];
        $url = rtrim($gateway, '/') . '/api/service/services/?' . http_build_query($queryParams);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $key,
            "Content-Type: application/json",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            echo json_encode(['error' => 'cURL Error: ' . $curlError]);
            exit;
        }

        if ($httpCode === 200) {
            echo $response;
        } else {
            echo json_encode([
                'error' => 'API returned HTTP status code ' . $httpCode,
                'raw' => substr(strip_tags($response ?: ''), 0, 300)
            ]);
        }
        exit;
    }
}

// Endpoint for dynamic search history fetching (Proxy to bypass CORS during polling)
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $key = $_GET['api_key'] ?? '';
    $gateway = $_GET['base_url'] ?? 'mock_php';
    $historyId = (int)($_GET['history_id'] ?? 0);

    if ($gateway === 'mock_php') {
        // Return simulated completed history check
        $mockReport = [
            "id" => $historyId,
            "status" => "Done",
            "service" => "Basic IMEI Check",
            "token_request_price" => "0.00",
            "result" => [
                "imei" => "353541326469521",
                "brand" => "Apple",
                "model" => "iPhone 12 Pro Max",
                "tac" => "35354132",
                "blacklist_status" => "CLEAN",
                "carrier_lock" => false,
                "original_carrier" => "T-Mobile Polska",
                "purchase_country" => "Poland",
                "specifications" => [
                    "cpu" => "Apple A14 Bionic",
                    "ram_gb" => 6,
                    "storage_gb" => 128,
                    "screen_size" => "6.7 inches"
                ]
            ]
        ];
        echo json_encode($mockReport);
        exit;
    } else {
        $queryParams = [
            'API_KEY' => $key,
            'format' => 'json',
        ];
        $url = rtrim($gateway, '/') . '/api/search_history/' . $historyId . '/?' . http_build_query($queryParams);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $key,
            "Content-Type: application/json",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            echo json_encode(['error' => 'cURL Error: ' . $curlError]);
            exit;
        }

        if ($httpCode === 200) {
            echo $response;
        } else {
            echo json_encode([
                'error' => 'API returned HTTP status code ' . $httpCode,
                'raw' => substr(strip_tags($response ?: ''), 0, 300)
            ]);
        }
        exit;
    }
}

// 2. Detect environment (CLI or Browser)
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    // =====================================================================
    // CLI VIEW (Terminal Output)
    // =====================================================================
    $apiKey = getenv('IMEI_API_KEY') ?: 'YOUR_BEARER_API_KEY';
    $baseUrl = getenv('IMEI_API_BASE_URL') ?: 'https://api.imei.info/v5';
    $serviceId = (int)(getenv('IMEI_SERVICE_ID') ?: 0);
    $testImei = '353541326469521';

    echo "=======================================================\n";
    echo "🚀 IMEI.info B2B API Quick Start (CLI Mode)\n";
    echo "=======================================================\n\n";

    $client = new ImeiCheckClient($apiKey, $baseUrl);
    try {
        echo "🔍 Querying details for IMEI: {$testImei} (Service ID: {$serviceId})...\n";
        $report = $client->checkImei($testImei, $serviceId);
        $brand = $report['brand'] ?? $report['result']['brand_name'] ?? $report['result']['brand'] ?? 'N/A';
        $model = $report['model'] ?? $report['result']['model_name'] ?? $report['result']['model'] ?? 'N/A';
        echo "✅ Success!\nBrand: {$brand}\nModel: {$model}\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
    exit;
}

// =====================================================================
// BROWSER VIEW (Premium HTML/CSS Glassmorphic Dashboard)
// =====================================================================

// Handle form submission via POST
$apiKey = $_POST['api_key'] ?? '';
$imei = $_POST['imei'] ?? '353541326469521';
$baseUrl = $_POST['base_url'] ?? 'https://dash.imei.info';
$serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$requestMode = $_POST['request_mode'] ?? 'sync';

// Store API Token in cookies for easy future test page reloads (must be sent before HTML output)
if (!empty($apiKey)) {
    setcookie('api_token', $apiKey, time() + (3600 * 24 * 30), "/");
}

$report = null;
$error = null;
$errorClass = '';
$rawJsonResponse = null;
$isPending = false;

$displayBrand = 'Unknown';
$displayModel = 'Unknown';
$displayImei = $imei;
$displayTac = 'N/A';
$displayBlacklist = 'CLEAN';
$displayLock = false;
$displayCountry = 'N/A';
$displayCarrier = 'N/A';
$displaySpecs = null;
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($apiKey) && !empty($imei)) {
    try {
        if ($baseUrl === 'mock_php') {
            // Local PHP Mock Mode - simulates a successful/failed API response locally without network requests
            if ($imei === '353541326469521') {
                $report = [
                    "imei" => $imei,
                    "brand" => "Apple",
                    "model" => "iPhone 12 Pro Max",
                    "tac" => "35354132",
                    "blacklist_status" => "CLEAN",
                    "carrier_lock" => false,
                    "original_carrier" => "T-Mobile Polska",
                    "purchase_country" => "Poland",
                    "specifications" => [
                        "cpu" => "Apple A14 Bionic",
                        "ram_gb" => 6,
                        "storage_gb" => 128,
                        "screen_size" => "6.7 inches"
                    ]
                ];
            } elseif ($imei === '350545260771498') {
                $report = [
                    "imei" => $imei,
                    "brand" => "Samsung",
                    "model" => "Galaxy S24 Ultra",
                    "tac" => "35054526",
                    "blacklist_status" => "CLEAN",
                    "carrier_lock" => false,
                    "original_carrier" => "Orange Polska",
                    "purchase_country" => "Poland",
                    "specifications" => [
                        "cpu" => "Snapdragon 8 Gen 3",
                        "ram_gb" => 12,
                        "storage_gb" => 256,
                        "screen_size" => "6.8 inches"
                    ]
                ];
            } elseif ($imei === '355030794352540') {
                $report = [
                    "imei" => $imei,
                    "brand" => "Google",
                    "model" => "Pixel 8 Pro",
                    "tac" => "35503079",
                    "blacklist_status" => "BLACKLISTED",
                    "carrier_lock" => true,
                    "original_carrier" => "T-Mobile USA",
                    "purchase_country" => "United States",
                    "specifications" => [
                        "cpu" => "Google Tensor G3",
                        "ram_gb" => 12,
                        "storage_gb" => 128,
                        "screen_size" => "6.7 inches"
                    ]
                ];
            } else {
                throw new ImeiInsufficientCreditsException("Your API balance is $0.00. Please recharge your account in the developer dashboard at dash.imei.info before executing queries.", 402, 402, "insufficient_credits", "");
            }
            $rawJsonResponse = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            // Normal API call — sync=true will block server-side until result is ready
            $client = new ImeiCheckClient($apiKey, $baseUrl, 30.0);
            $report = $client->checkImei($imei, $serviceId, $requestMode === 'sync', 25);
            $rawJsonResponse = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($report) {
            $status = strtolower((string)($report['status'] ?? ''));
            $isPending = in_array($status, ['pending', 'processing', 'running'], true)
                || (isset($report['http_code']) && $report['http_code'] === 202 && $status !== 'done');
            
            // Normalize report values for robust display on both Mock and Production API structures
            $displayBrand = $report['brand'] ?? $report['result']['brand_name'] ?? $report['result']['brand'] ?? 'Unknown';
            $displayModel = $report['model'] ?? $report['result']['model_name'] ?? $report['result']['model'] ?? 'Unknown';
            $displayImei = $report['imei'] ?? $imei;
            $displayTac = $report['tac'] ?? 'N/A';
            $displayBlacklist = $report['blacklist_status'] ?? $report['result']['blacklist_status'] ?? 'CLEAN';
            $displayLock = $report['carrier_lock'] ?? $report['result']['carrier_lock'] ?? false;
            $displayCountry = $report['purchase_country'] ?? $report['result']['purchase_country'] ?? 'N/A';
            $displayCarrier = $report['original_carrier'] ?? $report['result']['original_carrier'] ?? 'N/A';
            $displaySpecs = $report['specifications'] ?? $report['result']['specifications'] ?? null;
            
            if (!$isPending) {
                if (!empty($report['result']) && is_array($report['result'])) {
                    $details = $report['result'];
                } else {
                    $exclude = ['ulid', 'status', 'service', 'token_request_price', 'message', 'history_id', 'http_code'];
                    foreach ($report as $key => $val) {
                        if (!in_array($key, $exclude, true)) {
                            $details[$key] = $val;
                        }
                    }
                }
            }
        }
    } catch (ImeiAuthenticationException $e) {
        $error = "Authorization Failed (401): The API token is invalid or expired. Check your credentials.";
        $errorClass = 'auth-error';
        if ($e->getRawResponse()) {
            $rawJsonResponse = $e->getRawResponse();
        }
    } catch (ImeiInsufficientCreditsException $e) {
        $error = "Payment Required (402): Out of API lookup credits. Recharge balance on dash.imei.info.";
        $errorClass = 'billing-error';
        if ($e->getRawResponse()) {
            $rawJsonResponse = $e->getRawResponse();
        }
    } catch (ImeiValidationException $e) {
        $error = "Validation Error (422): The IMEI failed format rules or Luhn checksum.";
        $errorClass = 'validation-error';
        if ($e->getRawResponse()) {
            $rawJsonResponse = $e->getRawResponse();
        }
    } catch (ImeiException $e) {
        $error = "SDK Error (Status: " . ($e->getStatusCode() ?? 'N/A') . "): " . $e->getMessage();
        if ($e->getRawResponse()) {
            $rawText = trim(strip_tags($e->getRawResponse()));
            if (!empty($rawText)) {
                $error .= " | Server Response: " . substr($rawText, 0, 250) . "...";
            }
            $rawJsonResponse = $e->getRawResponse();
        }
        $errorClass = 'api-error';
    } catch (Exception $e) {
        $error = "System Error: " . $e->getMessage();
        $errorClass = 'system-error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMEI.info B2B API - Live Sandbox & Verification</title>
    <!-- Modern Premium Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.15);
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-orange: #f59e0b;
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.07);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient Glowing Background Lights */
        body::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, var(--primary-glow) 0%, rgba(0,0,0,0) 70%);
            top: -200px;
            left: -200px;
            z-index: -1;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.07) 0%, rgba(0,0,0,0) 70%);
            bottom: -100px;
            right: -100px;
            z-index: -1;
            pointer-events: none;
        }

        .container {
            max-width: 1000px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            z-index: 1;
        }

        /* Header Styling */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0.5rem;
        }

        .logo-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: 14px;
            padding: 0.45rem 1rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .verified-badge {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Glassmorphic Panel Layout */
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 768px) {
            .grid {
                grid-template-columns: 420px 1fr;
            }
        }

        .panel {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ffffff;
        }

        /* Interactive Forms */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .form-input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--glass-border);
            padding: 0.85rem 1rem;
            border-radius: 12px;
            color: var(--text-main);
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.2);
            background: rgba(0, 0, 0, 0.5);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%239ca3af' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-repeat: no-repeat;
            background-size: 1.25rem;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, #1d4ed8 100%);
            border: none;
            padding: 1rem;
            border-radius: 12px;
            color: #ffffff;
            font-family: inherit;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
            filter: brightness(1.1);
        }

        /* Error notification banner */
        .error-card {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .error-card svg {
            color: var(--accent-red);
            flex-shrink: 0;
            margin-top: 0.15rem;
        }

        .error-card-content h4 {
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .error-card-content p {
            color: var(--text-muted);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .error-card-tips {
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.8rem;
            color: var(--accent-orange);
            font-weight: 600;
        }

        /* Success Results View */
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--glass-border);
            padding-bottom: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .result-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #ffffff;
        }

        .result-header p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .status-pill {
            padding: 0.4rem 1rem;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .status-clean {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.1);
        }

        .status-blacklisted {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--accent-red);
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.1);
        }

        /* Spec Highlights Card Grid */
        .highlights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .highlight-card {
            background: rgba(255, 255, 255, 0.015);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 1rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
        }

        .highlight-card .label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .highlight-card .value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
        }

        /* Detail List */
        .details-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            background: rgba(0, 0, 0, 0.15);
            border-radius: 16px;
            padding: 1.25rem;
            border: 1px solid rgba(255, 255, 255, 0.02);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }

        .detail-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-row span.name {
            color: var(--text-muted);
            font-weight: 500;
        }

        .detail-row span.val {
            color: #ffffff;
            font-weight: 600;
        }

        /* Placeholder Welcome State */
        .welcome-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .welcome-state svg {
            color: var(--primary);
            opacity: 0.6;
            margin-bottom: 1.5rem;
            animation: pulse 3s infinite alternate;
        }

        .welcome-state h3 {
            color: #ffffff;
            font-weight: 600;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
        }

        .welcome-state p {
            max-width: 420px;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        @keyframes pulse {
            0% { transform: scale(1); filter: brightness(1); }
            100% { transform: scale(1.05); filter: brightness(1.3); }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Premium Header -->
    <header>
        <div class="logo-group">
            <img src="https://raw.githubusercontent.com/imei-info/.github/main/profile/images/imei-info-banner-dark.svg" alt="IMEI.info Banner" style="height: 38px; width: auto; display: block; border-radius: 6px;">
            <span class="verified-badge">B2B Integration</span>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            API Version: <strong>5.0.0 (Latest)</strong>
        </div>
    </header>

    <div class="grid">
        
        <!-- Controls Sidebar -->
        <div class="panel">
            <h3 class="panel-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                Sandbox Control Panel
            </h3>
            
            <form method="POST" action="">
                
                <div class="form-group">
                    <label for="api_key" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span>IMEI.info Secure API Key</span>
                        <button type="button" id="clear_api_key_btn" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: var(--accent-red); padding: 0.15rem 0.5rem; font-size: 0.7rem; font-weight: 600; border-radius: 6px; cursor: pointer; transition: all 0.2s ease;">
                            🗑️ Clear
                        </button>
                    </label>
                    <input type="text" id="api_key" name="api_key" class="form-input" 
                           placeholder="Paste Secure API Key here..." 
                           value="<?php echo htmlspecialchars($apiKey ?: (isset($_COOKIE['api_token']) ? $_COOKIE['api_token'] : '')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="imei">15-Digit IMEI to Verify</label>
                    <input type="text" id="imei" name="imei" class="form-input" 
                           placeholder="358742091234567" 
                           value="<?php echo htmlspecialchars($imei); ?>" required maxlength="15">
                </div>

                <div class="form-group">
                    <label for="service_id">Service ID</label>
                    <input type="number" id="service_id" name="service_id" class="form-input" 
                           placeholder="0" 
                           value="<?php echo htmlspecialchars((string)$serviceId); ?>" required min="0">
                </div>

                <div class="form-group">
                    <label style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em;">Select Service</span>
                        <button type="button" id="sync_services_btn" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: var(--primary); padding: 0.25rem 0.6rem; font-size: 0.75rem; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 0.25rem;">
                            🔄 Sync Services
                        </button>
                    </label>
                    <select id="services_select" class="form-input form-select">
                        <option value="0">Basic IMEI Check ($Token based) [ID: 0]</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="request_mode" style="display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em;">Request Mode</label>
                    <select id="request_mode" name="request_mode" class="form-input form-select">
                        <option value="sync" <?php echo $requestMode === 'sync' ? 'selected' : ''; ?>>
                            ⚡ Instant Mode (api-sync)
                        </option>
                        <option value="async" <?php echo $requestMode === 'async' ? 'selected' : ''; ?>>
                            ⏳ Queue Mode (api)
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="base_url">Target Gateway</label>
                    <select id="base_url" name="base_url" class="form-input form-select">
                        <option value="https://dash.imei.info" <?php echo $baseUrl === 'https://dash.imei.info' ? 'selected' : ''; ?>>
                            🚀 Production API (dash.imei.info)
                        </option>
                        <option value="mock_php" <?php echo $baseUrl === 'mock_php' ? 'selected' : ''; ?>>
                            ✨ Local PHP Simulator (No Network Required)
                        </option>
                    </select>
                </div>

                <button type="submit" class="submit-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    Query B2B API Lookup
                </button>
            </form>
            
            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--glass-border); font-size: 0.8rem; color: var(--text-muted); line-height: 1.4;">
                💡 <strong>Sandbox Test Numbers:</strong><br>
                • <strong style="color: var(--text-main);">353541326469521</strong> : Returns Apple<br>
                • <strong style="color: var(--text-main);">350545260771498</strong> : Returns Samsung<br>
                • <strong style="color: var(--text-main);">355030794352540</strong> : Returns Google<br>
            </div>
        </div>

        <!-- Details Output Panel -->
        <div class="panel" style="flex-grow: 1;">
            
            <?php if ($error): ?>
                <!-- Styled Beautiful Error Notification Card -->
                <div class="error-card">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <div class="error-card-content">
                        <h4>API Communication Exception</h4>
                        <p><?php echo htmlspecialchars($error); ?></p>
                        
                        <?php if (strpos($error, 'Could not resolve host') !== false): ?>
                            <div class="error-card-tips">
                                💡 <strong>Tip:</strong> Your hosting server blocks outgoing connections (cURL) via DNS or firewall. Please ask cFolks support to unblock outgoing ports 80/443 for your domain!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($report): ?>
                <?php if ($isPending): ?>
                    <!-- Active Queue Mode / 202 Polling Screen -->
                    <div class="result-header" style="border-bottom: none; margin-bottom: 0.5rem;">
                        <div>
                            <h2>Order Queued & Processing...</h2>
                            <p style="color: var(--accent-orange); font-weight: 600; margin-top: 0.5rem;">
                                Status: ⏳ Pending (HTTP 202 Accepted)
                            </p>
                        </div>
                    </div>
                    <div class="details-list" id="polling_container" style="text-align: center; padding: 3rem 2rem;">
                        <div class="spinner" style="border: 4px solid rgba(255,255,255,0.1); border-left-color: var(--primary); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 1.5rem auto;"></div>
                        <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: #fff;">Retrieving Report...</p>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                            Order ID: <strong id="history_id_val"><?php echo htmlspecialchars((string)($report['history_id'] ?? 'N/A')); ?></strong><br>
                            The B2B Gateway has queued the request. We are polling for the result.
                        </p>
                        <button type="button" id="check_status_btn" class="submit-btn" style="max-width: 250px; margin: 0 auto; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: var(--primary); box-shadow: none;">
                            🔄 Check Status Now
                        </button>
                    </div>
                <?php else: ?>
                    <!-- Styled Success Output Dashboard -->
                    <div class="result-header">
                        <div>
                            <?php 
                                $title = (!empty($displayBrand) && $displayBrand !== 'Unknown') 
                                    ? ($displayBrand . ' ' . $displayModel) 
                                    : ($report['service'] ?? 'Basic IMEI Check');
                            ?>
                            <h2><?php echo htmlspecialchars($title); ?></h2>
                            <p>IMEI: <?php echo htmlspecialchars($displayImei); ?> | ULID: <?php echo htmlspecialchars($report['ulid'] ?? 'N/A'); ?></p>
                        </div>
                        <?php 
                            $isBlacklisted = strtoupper($displayBlacklist) === 'BLACKLISTED';
                            if ($isBlacklisted):
                        ?>
                            <span class="status-pill status-blacklisted">
                                BLACKLISTED 🚨
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Highlights Spec Grid (Only showing metadata if present) -->
                    <div class="highlights-grid">
                        <div class="highlight-card">
                            <span class="label">Service Name</span>
                            <span class="value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($report['service'] ?? 'IMEI Lookup'); ?></span>
                        </div>
                        <div class="highlight-card">
                            <span class="label">Request Price</span>
                            <span class="value" style="color: var(--accent-green);">
                                $<?php echo htmlspecialchars($report['token_request_price'] ?? '0.00'); ?>
                            </span>
                        </div>
                        <div class="highlight-card">
                            <span class="label">Status</span>
                            <span class="value" style="color: var(--accent-green);"><?php echo htmlspecialchars($report['status'] ?? 'Done'); ?></span>
                        </div>
                    </div>

                    <!-- Full Dynamic Details List (Renders 100% of returned keys dynamically) -->
                    <div class="details-list">
                        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: #ffffff; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="9" x2="15" y2="9"></line><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg>
                            Technical Details & Specifications:
                        </h3>
                        
                        <?php if (!empty($details) && is_array($details)): ?>
                            <?php foreach ($details as $key => $value): ?>
                                <?php 
                                    // Format the key beautifully (e.g. 'brand_name' -> 'Brand Name')
                                    $label = ucwords(str_replace(['_', '-'], ' ', $key));
                                    
                                    // Format the value appropriately
                                    if (is_bool($value)) {
                                        $valText = $value ? 'Yes 🔒' : 'No 🔓';
                                    } elseif ($value === null) {
                                        $valText = 'N/A';
                                    } elseif (is_array($value)) {
                                        $valText = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                    } else {
                                        $valText = (string)$value;
                                    }
                                ?>
                                <div class="detail-row">
                                    <span class="name"><?php echo htmlspecialchars($label); ?></span>
                                    <span class="val" style="text-transform: uppercase;"><?php echo htmlspecialchars($valText); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="detail-row">
                                <span class="name">No details returned</span>
                                <span class="val">Verify Service ID configuration</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Collapsible Raw Response Panel -->
                <?php if (!empty($rawJsonResponse)): ?>
                    <div style="margin-top: 1.5rem; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--glass-border); border-radius: 16px; padding: 1.25rem;">
                        <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--text-muted); cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="document.getElementById('raw_json_block').style.display = document.getElementById('raw_json_block').style.display === 'none' ? 'block' : 'none'">
                            <span>🛠️ Developer Console (Raw API JSON)</span>
                            <span style="font-size: 0.75rem; color: var(--primary);">[Toggle View]</span>
                        </h4>
                        <pre id="raw_json_block" style="display: none; background: #050811; border: 1px solid rgba(255,255,255,0.05); padding: 1rem; border-radius: 10px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; color: #a5b4fc; max-height: 350px; text-align: left; white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($rawJsonResponse); ?></pre>
                    </div>
                <?php endif; ?>
            <?php elseif (!$error): ?>
                <!-- Welcome/Ready State when no query is performed yet -->
                <div class="welcome-state">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                    <h3>Official Developer Sandbox Portal</h3>
                    <p>Enter your B2B Bearer Token and a test 15-digit IMEI number in the sidebar, then click "Query B2B API Lookup" to run a live SDK call and render the dynamic specifications report!</p>
                </div>
            <?php endif; ?>

        </div>
        
    </div>

</div>

<script>
// Expose PHP variables to JavaScript for async polling
const PENDING_HISTORY_ID = <?php echo (int)($report['history_id'] ?? 0); ?>;
const IS_PENDING_MODE = <?php echo $isPending ? 'true' : 'false'; ?>;
const API_KEY_POLL = <?php echo json_encode($apiKey); ?>;
const BASE_URL_POLL = <?php echo json_encode($baseUrl); ?>;

document.addEventListener('DOMContentLoaded', function() {
    const syncBtn = document.getElementById('sync_services_btn');
    const servicesSelect = document.getElementById('services_select');
    const serviceIdInput = document.getElementById('service_id');
    const apiKeyInput = document.getElementById('api_key');
    const baseUrlSelect = document.getElementById('base_url');
    const clearApiKeyBtn = document.getElementById('clear_api_key_btn');

    // 1. Session Token Clearing
    if (clearApiKeyBtn) {
        clearApiKeyBtn.addEventListener('click', function() {
            document.cookie = "api_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            apiKeyInput.value = '';
            alert('B2B API Token cleared from browser cookies.');
        });
    }

    // 2. Services List Synchronization
    if (syncBtn) {
        syncBtn.addEventListener('click', async function() {
            const apiKey = apiKeyInput.value.trim();
            const baseUrl = baseUrlSelect.value;

            if (!apiKey && baseUrl !== 'mock_php') {
                alert('Please enter your API key in the "IMEI.info Secure API Key" field first.');
                return;
            }

            syncBtn.disabled = true;
            syncBtn.textContent = '⏳ Syncing...';

            try {
                const url = `quick_start.php?action=get_services&api_key=${encodeURIComponent(apiKey)}&base_url=${encodeURIComponent(baseUrl)}`;
                const response = await fetch(url);
                const data = await response.json();

                if (data.error) {
                    alert('Synchronization error: ' + data.error + (data.raw ? '\n\nServer response:\n' + data.raw : ''));
                } else if (Array.isArray(data)) {
                    servicesSelect.innerHTML = '';
                    data.forEach(service => {
                        const price = service.price !== undefined ? service.price : 'N/A';
                        const priceText = typeof price === 'number' ? `$${price.toFixed(2)}` : `$${price}`;
                        const option = document.createElement('option');
                        option.value = service.id;
                        option.textContent = `${service.name} (${priceText}) [ID: ${service.id}]`;
                        if (String(service.id) === String(serviceIdInput.value)) {
                            option.selected = true;
                        }
                        servicesSelect.appendChild(option);
                    });
                    alert(`Successfully synchronized ${data.length} services!`);
                } else {
                    alert('Unexpected API response.');
                }
            } catch (err) {
                console.error(err);
                alert('An error occurred while connecting to the proxy server: ' + err.message);
            } finally {
                syncBtn.disabled = false;
                syncBtn.textContent = '🔄 Sync Services';
            }
        });
    }

    // Synchronize select to input value
    servicesSelect.addEventListener('change', function() {
        serviceIdInput.value = this.value;
    });

    // Also update select if user manually types in the input
    serviceIdInput.addEventListener('input', function() {
        const val = this.value;
        let found = false;
        for (let i = 0; i < servicesSelect.options.length; i++) {
            if (servicesSelect.options[i].value === val) {
                servicesSelect.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            servicesSelect.value = '';
        }
    });

    // 3. Dynamic HTTP 202 Polling Workflow
    if (IS_PENDING_MODE && PENDING_HISTORY_ID > 0) {
        let pollInterval = setInterval(checkOrderStatus, 3000);
        const checkBtn = document.getElementById('check_status_btn');

        if (checkBtn) {
            checkBtn.addEventListener('click', checkOrderStatus);
        }

        async function checkOrderStatus() {
            try {
                if (checkBtn) {
                    checkBtn.disabled = true;
                    checkBtn.textContent = '⏳ Checking...';
                }

                const url = `quick_start.php?action=get_history&history_id=${PENDING_HISTORY_ID}&api_key=${encodeURIComponent(API_KEY_POLL)}&base_url=${encodeURIComponent(BASE_URL_POLL)}`;
                const response = await fetch(url);
                const data = await response.json();

                if (data.error) {
                    console.error('Error fetching order status:', data.error);
                } else if (data.status === 'Done') {
                    clearInterval(pollInterval);
                    renderFinalResult(data);
                } else if (data.status === 'Failed') {
                    clearInterval(pollInterval);
                    const pollingContainer = document.getElementById('polling_container');
                    if (pollingContainer) {
                        pollingContainer.innerHTML = `
                            <div style="color: var(--accent-red); font-size: 3rem; margin-bottom: 1rem;">🚨</div>
                            <h4 style="color: #fff; font-size: 1.2rem; margin-bottom: 0.5rem;">Order Processing Failed</h4>
                            <p style="color: var(--text-muted); font-size: 0.95rem;">The B2B service returned status "Failed". Verify device credentials or try again.</p>
                        `;
                    }
                }
            } catch (err) {
                console.error('Network error during polling:', err);
            } finally {
                if (checkBtn) {
                    checkBtn.disabled = false;
                    checkBtn.textContent = '🔄 Check Status Now';
                }
            }
        }

        function renderFinalResult(data) {
            const pollingContainer = document.getElementById('polling_container');
            if (!pollingContainer) return;

            const containerPanel = pollingContainer.parentElement;
            const details = data.result || {};
            const displayBrand = details.brand_name || details.brand || 'Unknown';
            const displayModel = details.model_name || details.model || 'Unknown';
            const displayImei = details.imei || '';
            const displayUlid = data.ulid || 'N/A';
            const displayPrice = data.token_request_price || '0.00';
            
            let html = `
                <!-- Styled Success Output Dashboard -->
                <div class="result-header">
                    <div>
                        <h2>${displayBrand} ${displayModel}</h2>
                        <p>IMEI: ${displayImei} | ULID: ${displayUlid}</p>
                    </div>
                </div>

                <!-- Highlights Spec Grid -->
                <div class="highlights-grid">
                    <div class="highlight-card">
                        <span class="label">Service Name</span>
                        <span class="value" style="font-size: 0.95rem;">${data.service || 'IMEI Lookup'}</span>
                    </div>
                    <div class="highlight-card">
                        <span class="label">Request Price</span>
                        <span class="value" style="color: var(--accent-green);">$${displayPrice}</span>
                    </div>
                    <div class="highlight-card">
                        <span class="label">Status</span>
                        <span class="value" style="color: var(--accent-green);">${data.status}</span>
                    </div>
                </div>

                <!-- Full Dynamic Details List -->
                <div class="details-list">
                    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: #ffffff; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="9" x2="15" y2="9"></line><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg>
                        Technical Details & Specifications:
                    </h3>
            `;

            const excludeKeys = ['ulid', 'status', 'service', 'token_request_price', 'message', 'history_id', 'http_code'];
            let rowCount = 0;
            
            for (const [key, value] of Object.entries(details)) {
                if (excludeKeys.includes(key)) continue;
                
                const label = key.replace(/[_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                let valText = '';
                if (typeof value === 'boolean') {
                    valText = value ? 'Yes 🔒' : 'No 🔓';
                } else if (value === null || value === undefined) {
                    valText = 'N/A';
                } else if (typeof value === 'object') {
                    valText = JSON.stringify(value, null, 2);
                } else {
                    valText = String(value);
                }
                
                html += `
                    <div class="detail-row">
                        <span class="name">${label}</span>
                        <span class="val" style="text-transform: uppercase;">${valText}</span>
                    </div>
                `;
                rowCount++;
            }

            if (rowCount === 0) {
                html += `
                    <div class="detail-row">
                        <span class="name">No details returned</span>
                        <span class="val">Verify Service ID configuration</span>
                    </div>
                `;
            }

            html += `</div>`;
            
            containerPanel.innerHTML = html;
        }
    }
});
</script>
 
</body>
</html>
