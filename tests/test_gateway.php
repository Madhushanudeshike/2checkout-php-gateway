<?php
declare(strict_types=1);

/**
 * Automated Security & Cryptography Verification Suite
 * Run via CLI: php tests/test_gateway.php
 */

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\Database;
use App\Logger;
use App\OrderService;
use App\Security;
use App\TwoCheckout\ApiClient;
use App\TwoCheckout\IpnHandler;
use App\TwoCheckout\ReturnValidator;

echo "===========================================================\n";
echo " 2Checkout Payment Gateway Security Verification Suite\n";
echo "===========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// Test 1: CSRF Protection
// -----------------------------------------------------------------------------
echo "[1] Testing CSRF Token Security...\n";
$token1 = Security::generateCsrfToken();
assertTest("CSRF token is 64 hex characters (256-bit entropy)", strlen($token1) === 64);
assertTest("CSRF token validates against itself", Security::validateCsrfToken($token1));
assertTest("CSRF token fails on tampering", !Security::validateCsrfToken($token1 . 'a'));
assertTest("CSRF token fails on empty/null", !Security::validateCsrfToken(null));

// -----------------------------------------------------------------------------
// Test 2: Input Sanitization & Validation
// -----------------------------------------------------------------------------
echo "\n[2] Testing Input Validation & Sanitization...\n";
assertTest("Valid email succeeds", Security::validateEmail("user@example.com") === "user@example.com");
assertTest("Invalid email fails", Security::validateEmail("user@example") === null);
assertTest("Valid country ISO code accepted", Security::validateCountryCode("us") === "US");
assertTest("Invalid country code rejected", Security::validateCountryCode("USA") === null);
assertTest("Valid currency accepted", Security::validateCurrency("usd") === "USD");
assertTest("Unsupported currency rejected", Security::validateCurrency("XYZ") === null);
assertTest("Valid amount parsed", Security::validateAmount("19.99") === 19.99);
assertTest("Negative or zero amount rejected", Security::validateAmount("-5.00") === null);

// -----------------------------------------------------------------------------
// Test 3: 2Checkout IPN HMAC-SHA256 Serialization & Signature Verification
// -----------------------------------------------------------------------------
echo "\n[3] Testing 2Checkout IPN Cryptographic Verification...\n";
$testSecretKey = "TEST_SECRET_KEY_123456789";
$ipnHandler = new IpnHandler($testSecretKey);

$sampleIpnPayload = [
    'IPN_PID' => ['12345'],
    'IPN_PNAME' => ['Starter Plan'],
    'IPN_DATE' => '20261020000000',
    'ORDERNO' => '987654321',
    'ORDERSTATUS' => 'COMPLETE',
    'REFNO' => '11223344',
    'REFNOEXT' => 'ORD-TEST-001',
    'IPN_TOTALGENERAL' => '19.99'
];

// Calculate reference signature
$serialized = $ipnHandler->serializePayload($sampleIpnPayload);
$expectedHmac = hash_hmac('sha256', $serialized, $testSecretKey);

// Attach signature to payload
$validIpnPayload = $sampleIpnPayload;
$validIpnPayload['SIGNATURE_SHA2_256'] = $expectedHmac;

assertTest("IPN valid HMAC-SHA256 signature is accepted", $ipnHandler->verifySignature($validIpnPayload));

// Test rejection on tampered payload
$tamperedPayload = $validIpnPayload;
$tamperedPayload['IPN_TOTALGENERAL'] = '9.99'; // Tampered amount
assertTest("IPN with tampered amount is rejected", !$ipnHandler->verifySignature($tamperedPayload));

// Test rejection on fraudulent signature
$fraudPayload = $validIpnPayload;
$fraudPayload['SIGNATURE_SHA2_256'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
assertTest("IPN with forged signature is rejected", !$ipnHandler->verifySignature($fraudPayload));

// Test Read Receipt Generation
$receipt = $ipnHandler->generateReceipt($validIpnPayload);
assertTest("IPN receipt matches 2Checkout <EPAYMENT> format", str_starts_with($receipt, '<EPAYMENT>') && str_ends_with($receipt, '</EPAYMENT>'));

// -----------------------------------------------------------------------------
// Test 4: Return URL Signature Verification
// -----------------------------------------------------------------------------
echo "\n[4] Testing Return URL Buy-Link Secret Word Verification...\n";
$testSecretWord = "TEST_SECRET_WORD_XYZ";
$returnValidator = new ReturnValidator($testSecretWord);

$returnParams = [
    'refno' => '11223344',
    'total' => '19.99',
    'total-currency' => 'USD',
    'order-ext-ref' => 'ORD-TEST-001'
];

// Generate valid signature using same algorithm
$returnSerialized = $returnValidator->serializeParameters($returnParams);
$returnSignature = hash_hmac('sha256', $returnSerialized, $testSecretWord);

$validReturnParams = $returnParams;
$validReturnParams['signature'] = $returnSignature;

assertTest("Return URL valid signature accepted", $returnValidator->validate($validReturnParams));

$tamperedReturn = $validReturnParams;
$tamperedReturn['total'] = '1.00'; // Altered price in return url
assertTest("Return URL with altered parameters rejected", !$returnValidator->validate($tamperedReturn));

// -----------------------------------------------------------------------------
// Test 5: REST API 6.0 HMAC Authentication Header Generation
// -----------------------------------------------------------------------------
echo "\n[5] Testing REST API 6.0 HMAC Authentication Header...\n";
$apiClient = new ApiClient('250000000000', 'SECRET_KEY_SAMPLE');
$testDate = '2026-10-20 12:00:00';
$authHeader = $apiClient->generateAuthHeader($testDate);

assertTest("REST API Auth Header contains code, date, and hash", 
    str_contains($authHeader, 'code="250000000000"') && 
    str_contains($authHeader, 'date="2026-10-20 12:00:00"') && 
    str_contains($authHeader, 'hash="')
);

// -----------------------------------------------------------------------------
// Test 6: Database & Order Lifecycle & Idempotency
// -----------------------------------------------------------------------------
echo "\n[6] Testing Database Schema, Order Creation & Idempotency...\n";
try {
    $order = OrderService::createOrder(
        'PROD-BASIC',
        'Security Test User',
        'test@example.com',
        'US',
        ['test' => true]
    );

    assertTest("Order successfully created with generated reference", !empty($order['order_ref']));
    assertTest("Order price strictly locked to catalog price ($19.99)", (float)$order['amount'] === 19.99);
    assertTest("Order status initialized to PENDING", $order['status'] === 'PENDING');

    $fetched = OrderService::getOrderByRef($order['order_ref']);
    assertTest("Order fetched securely from database by reference", $fetched && $fetched['id'] === $order['id']);

    // Test IPN state transition and idempotency
    $mockIpn = [
        'REFNO' => 'TCO-998877',
        'ORDERNO' => '998877',
        'ORDERSTATUS' => 'COMPLETE',
        'REFNOEXT' => $order['order_ref'],
        'IPN_TOTALGENERAL' => '19.99'
    ];

    $ipnProcessed1 = OrderService::handleIpn($mockIpn);
    assertTest("IPN webhook successfully updates order to COMPLETED", $ipnProcessed1 === true);

    $updatedOrder = OrderService::getOrderByRef($order['order_ref']);
    assertTest("Order status in DB is now COMPLETED", $updatedOrder['status'] === 'COMPLETED');

    // Idempotency test: Re-send same IPN
    $ipnProcessed2 = OrderService::handleIpn($mockIpn);
    assertTest("Duplicate IPN recognized and idempotently skipped without errors", $ipnProcessed2 === true);

} catch (\Throwable $e) {
    assertTest("Database & Order lifecycle failed: " . $e->getMessage(), false);
}

// -----------------------------------------------------------------------------
// Test 7: Sensitive Data Redaction in Logger
// -----------------------------------------------------------------------------
echo "\n[7] Testing Sensitive Data Redaction in Logger...\n";
$dirtyData = [
    'card_number' => '4111111111111111',
    'cvv' => '123',
    'ees_token' => 'secret_token_value',
    'secret_key' => 'top_secret_key',
    'customer_name' => 'John Doe'
];

$cleanData = Logger::redactSensitiveData($dirtyData);
assertTest("Card number redacted", $cleanData['card_number'] === '[REDACTED]');
assertTest("CVV redacted", $cleanData['cvv'] === '[REDACTED]');
assertTest("Payment token redacted", $cleanData['ees_token'] === '[REDACTED]');
assertTest("Secret key redacted", $cleanData['secret_key'] === '[REDACTED]');
assertTest("Customer name preserved", $cleanData['customer_name'] === 'John Doe');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n===========================================================\n";
echo " Verification Results: {$passed} Passed, {$failed} Failed\n";
echo "===========================================================\n";

if ($failed === 0) {
    echo " ALL SECURITY & CRYPTOGRAPHIC VERIFICATIONS PASSED!\n\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED!\n\n";
    exit(1);
}
