<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Logger;
use App\OrderService;
use App\TwoCheckout\IpnHandler;

// IPN webhooks must always arrive via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$ipnHandler = new IpnHandler();

// Capture raw POST data
$postData = $_POST;

if (empty($postData)) {
    http_response_code(400);
    exit('Bad Request: Empty Payload');
}

Logger::info("Incoming 2Checkout IPN webhook received", [
    'refno' => $postData['REFNO'] ?? 'N/A',
    'orderno' => $postData['ORDERNO'] ?? 'N/A',
    'status' => $postData['ORDERSTATUS'] ?? 'N/A',
    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
]);

// 1. Cryptographically verify the HMAC-SHA256 signature
if (!$ipnHandler->verifySignature($postData)) {
    Logger::security("Rejected illegitimate IPN webhook: Signature verification failed", [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
        'refno' => $postData['REFNO'] ?? 'N/A'
    ]);

    http_response_code(403);
    exit('Access Denied: Invalid Signature');
}

// 2. Process order update with database idempotency protection
try {
    $processed = OrderService::handleIpn($postData);

    if (!$processed) {
        Logger::warning("IPN webhook could not be processed into order store");
    }

    // 3. Mandatory Read Receipt Output:
    // 2Checkout requires the exact <EPAYMENT>DATE|HASH</EPAYMENT> format.
    // If not returned, 2Checkout will assume failure and retry delivery repeatedly.
    $receipt = $ipnHandler->generateReceipt($postData);

    header('Content-Type: text/html; charset=UTF-8');
    echo $receipt;
    exit;

} catch (\Throwable $e) {
    Logger::error("Fatal exception during IPN processing: " . $e->getMessage(), [
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    exit('Internal Processing Error');
}
