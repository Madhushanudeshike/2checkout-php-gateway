<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Logger;
use App\OrderService;
use App\Security;

// Apply security headers
Security::sendSecurityHeaders();

header('Content-Type: application/json; charset=UTF-8');

// Ensure request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed. POST required.'
    ]);
    exit;
}

// 1. IP Rate Limiting defense against bot card-testing
if (!Security::checkRateLimit('checkout')) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many payment attempts. Please wait a minute and try again.'
    ]);
    exit;
}

// Parse input (supports JSON payload or standard POST)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

// 2. Cryptographic CSRF Token Validation
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Security::validateCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Security token invalid or expired. Please refresh the page and try again.'
    ]);
    exit;
}

// 3. Strict Input Validation & Sanitization
$productCode = trim((string)($data['product_code'] ?? ''));
$eesToken = trim((string)($data['ees_token'] ?? ''));
$firstName = trim((string)($data['first_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$email = Security::validateEmail((string)($data['email'] ?? ''));
$country = Security::validateCountryCode((string)($data['country'] ?? 'US'));
$address = trim((string)($data['address'] ?? ''));
$city = trim((string)($data['city'] ?? ''));
$state = trim((string)($data['state'] ?? ''));
$zip = trim((string)($data['zip'] ?? ''));

if (empty($eesToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Payment token is missing or expired. Please re-enter card details.'
    ]);
    exit;
}

if (empty($productCode) || !OrderService::getProduct($productCode)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid product selection.'
    ]);
    exit;
}

if (empty($firstName) || empty($lastName) || !$email || !$country) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Please provide complete and valid billing details.'
    ]);
    exit;
}

try {
    // 4. Create Order in Database (Price is verified from server catalog, NOT client input!)
    $customerName = "{$firstName} {$lastName}";
    $order = OrderService::createOrder(
        $productCode,
        $customerName,
        $email,
        $country,
        ['address' => $address, 'city' => $city, 'state' => $state, 'zip' => $zip]
    );

    $billing = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'address' => $address ?: 'Main St',
        'city' => $city ?: 'Default City',
        'state' => $state ?: 'Default State',
        'zip' => $zip ?: '10001',
        'country' => $country
    ];

    // 5. Process payment authorization via 2Checkout REST API v6.0
    $paymentResult = OrderService::processPayment($order, $eesToken, $billing);

    if ($paymentResult['success']) {
        echo json_encode([
            'success' => true,
            'order_ref' => $order['order_ref'],
            'refno' => $paymentResult['refno'],
            'redirect_url' => $paymentResult['redirect_url'], // Used when 3D Secure challenge is required
            'status' => $paymentResult['status']
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'order_ref' => $order['order_ref'],
            'error' => $paymentResult['error'] ?? 'Payment authorization failed.'
        ]);
    }

} catch (\Throwable $e) {
    Logger::error("Order processing exception: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected internal error occurred while processing your order.'
    ]);
}
