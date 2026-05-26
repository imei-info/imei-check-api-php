# Official IMEI CHECK API Client for PHP by IMEI.info 🐘

[![Latest Stable Version](https://img.shields.io/packagist/v/imei-info/imei-check-api.svg?style=flat-square)](https://packagist.org/packages/imei-info/imei-check-api)
[![Total Downloads](https://img.shields.io/packagist/dt/imei-info/imei-check-api.svg?style=flat-square)](https://packagist.org/packages/imei-info/imei-check-api)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg?style=flat-square)](https://packagist.org/packages/imei-info/imei-check-api)

The official, production-ready B2B PHP integration library for the **IMEI.info API v5** — the ultimate global **IMEI checker API**.

Integrate the industry-leading **IMEI check API** into your PHP backend, custom WordPress plugins, WooCommerce checkout flows, or high-volume wholesale ERP platforms. Perform instant **IMEI check** and **IMEI lookup** requests to retrieve comprehensive device specifications from our global **TAC (Type Allocation Code)** and **TAC database**. Verify real-time **blacklist check** and **blacklist status (carrier lock, phone block)**, retrieve **iCloud check** details (Find My iPhone status), and identify **FRP bypass status (Google Factory Reset Protection)** instantly to automate your mobile trade-in, e-commerce, or recycling workflows.

---

## ⚡ Supported IMEI Check API Features & Services

In the modern mobile wholesale, recycling, and e-commerce industry, reliable **IMEI check** automation and live device verification are business-critical. This lightweight B2B library was engineered from the ground up to solve common integration headaches:

* **Instant Blacklist & Carrier Verification**: Query global databases to check GSMA status, perform a **blacklist check** or discover a device's exact **blacklist status (carrier lock, phone block)**.
* **Apple & Android Security Lock Checking**: Instantly execute an **iCloud check** to find Find My iPhone status, or verify **FRP bypass status (Google Factory Reset Protection)** to prevent locked device trade-ins.
* **TAC Identification**: Query our high-performance **TAC (Type Allocation Code)** catalog to match device model names, brands, and technical specs automatically from the official **TAC database**.
* **Zero Dependency Footprint**: Built purely with native PHP cURL. It does **not** depend on Guzzle or other HTTP libraries, preventing "dependency version hell" in complex frameworks, **WooCommerce**, and **WordPress** plugins.
* **Modern Strict PHP**: Developed under `strict_types=1` using PHP 8.1+ standards.
* **Structured Exceptions**: Automatically catches API error responses and wraps them in specialized, descriptive PHP exception classes (e.g., credit exhaustion, validation, authentication failure).
* **Interactive Sandbox Testing**: Supports seamless switching between local prototype environments, sandbox gateways, and production servers.

---

## 📥 Installation

Install the package via **Composer**:

```bash
composer require imei-info/imei-check-api
```

Ensure your server has the `curl` and `json` extensions enabled (both are standard in modern PHP installations).

---

## 🚀 Quick Start Guide

Before querying, you will need a secure B2B API Token. Register for free and generate your key in your developer dashboard at **[dash.imei.info](https://dash.imei.info)**.

### Basic Integration Example

```php
<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use ImeiInfo\ImeiCheck\ImeiCheckClient;
use ImeiInfo\ImeiCheck\Exceptions\ImeiAuthenticationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiInsufficientCreditsException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiValidationException;
use ImeiInfo\ImeiCheck\Exceptions\ImeiException;

// Initialize the B2B Client with your secure Bearer Token
$apiKey = 'YOUR_BEARER_API_KEY';
$client = new ImeiCheckClient($apiKey);

try {
    // Perform a live IMEI check
    $report = $client->checkImei('358742091234567');
    
    echo "Device: " . $report['brand'] . " " . $report['model'] . "\n";
    echo "Blacklist Status: " . $report['blacklist_status'] . "\n";
    echo "Carrier Lock: " . ($report['carrier_lock'] ? 'LOCKED' : 'UNLOCKED') . "\n";
    echo "Purchase Country: " . ($report['purchase_country'] ?? 'N/A') . "\n";
    
} catch (ImeiAuthenticationException $e) {
    // Handle HTTP 401: Invalid API Key
    echo "Auth Error: Ensure your API key is correct. " . $e->getMessage() . "\n";

} catch (ImeiInsufficientCreditsException $e) {
    // Handle HTTP 402: Account balance is out of credits
    echo "Billing Error: Please recharge your B2B balance on dash.imei.info. " . $e->getMessage() . "\n";

} catch (ImeiValidationException $e) {
    // Handle HTTP 422: Format constraint or Luhn checksum failure
    echo "Validation Error: The IMEI entered is invalid. Code: " . $e->getErrorCode() . "\n";

} catch (ImeiException $e) {
    // Catch-all for other HTTP statuses, connection timeouts, or network issues
    echo "Unexpected SDK Error (Code: " . $e->getStatusCode() . "): " . $e->getMessage() . "\n";
}
```

---

## 🧪 Free Sandbox Testing

You can fully test your software integration using our dedicated Sandbox test numbers without consuming paid API credits.

### 🎁 API Sandbox test IMEIs:
* `358742091234567` — Returns full specifications for an Apple iPhone 15 Pro Max (Status: `CLEAN`).
* `358742091234568` — Returns specs with `BLACKLISTED` status (stolen in T-Mobile USA).
* `358742091234569` — Simulates an invalid IMEI Luhn checksum (Triggers `ImeiValidationException` / HTTP 422).
* Any other IMEI — Simulates a `Payment Required` credit exhaustion error (Triggers `ImeiInsufficientCreditsException` / HTTP 402).

To query a local prototype mock server during integration testing, simply pass your local endpoint URL as the second argument to the constructor:

```php
$client = new ImeiCheckClient($apiKey, 'http://localhost:8000');
```

---

## 📈 Strategic Integration & Support

Need enterprise custom pricing, wholesale high-volume packages, custom webhooks, or custom hardware spec feeds?

* **Documentation Portal**: [https://www.imei.info/api/imei/docs/](https://www.imei.info/api/imei/docs/)
* **Interactive OpenAPI Specs**: [https://dash.imei.info/swagger/](https://dash.imei.info/swagger/)
* **Developer Panel**: [dash.imei.info](https://dash.imei.info)
* **B2B Tech Support**: [api@imei.info](mailto:api@imei.info)

Licensed under the MIT License. Developed and maintained by the IMEI.info Team.
