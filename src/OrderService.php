<?php
declare(strict_types=1);

namespace App;

use App\TwoCheckout\ApiClient;
use App\TwoCheckout\IpnHandler;
use App\TwoCheckout\ReturnValidator;
use PDO;

/**
 * Class OrderService
 * Core order lifecycle, server-side price validation, and idempotent state transitions.
 */
class OrderService
{
    /**
     * Server-controlled product catalog.
     * CRITICAL SECURITY PRINCIPLE: Never trust price or product details sent from frontend JavaScript!
     */
    public static array $products = [
        'PROD-BASIC' => [
            'name' => 'Starter Subscription Plan',
            'amount' => 19.99,
            'currency' => 'USD',
            'description' => 'Access to starter tools and weekly reports.'
        ],
        'PROD-PRO' => [
            'name' => 'Professional Developer Suite',
            'amount' => 49.99,
            'currency' => 'USD',
            'description' => 'Unlimited API requests, priority support, and team workspace.'
        ],
        'PROD-ENTERPRISE' => [
            'name' => 'Enterprise Security Package',
            'amount' => 149.00,
            'currency' => 'USD',
            'description' => 'Custom SLA, dedicated infrastructure, and 24/7 audit support.'
        ]
    ];

    /**
     * Get validated product details from catalog
     */
    public static function getProduct(string $productCode): ?array
    {
        return self::$products[$productCode] ?? null;
    }

    /**
     * Create a new pending order in the database
     */
    public static function createOrder(
        string $productCode,
        string $customerName,
        string $customerEmail,
        string $country,
        array $extraMetadata = []
    ): array {
        $product = self::getProduct($productCode);
        if (!$product) {
            throw new \InvalidArgumentException("Invalid product selected.");
        }

        $pdo = Database::getConnection();
        $orderRef = 'ORD-' . strtoupper(bin2hex(random_bytes(6))) . '-' . time();

        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_ref, product_code, product_name, amount, currency,
                customer_name, customer_email, country, status, metadata
            ) VALUES (
                :order_ref, :product_code, :product_name, :amount, :currency,
                :customer_name, :customer_email, :country, 'PENDING', :metadata
            )
        ");

        $stmt->execute([
            ':order_ref' => $orderRef,
            ':product_code' => $productCode,
            ':product_name' => $product['name'],
            ':amount' => $product['amount'],
            ':currency' => $product['currency'],
            ':customer_name' => $customerName,
            ':customer_email' => $customerEmail,
            ':country' => $country,
            ':metadata' => json_encode($extraMetadata)
        ]);

        return self::getOrderByRef($orderRef);
    }

    /**
     * Fetch order by internal reference
     */
    public static function getOrderByRef(string $orderRef): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_ref = :order_ref LIMIT 1");
        $stmt->execute([':order_ref' => $orderRef]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * Fetch order by 2Checkout reference number
     */
    public static function getOrderByTcoRefno(string $tcoRefno): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE tco_refno = :tco_refno LIMIT 1");
        $stmt->execute([':tco_refno' => $tcoRefno]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * Process checkout with 2Pay.js token
     */
    public static function processPayment(array $order, string $eesToken, array $billing): array
    {
        $apiClient = new ApiClient();

        $appUrl = Config::get('app_url');
        $returnUrl = "{$appUrl}/return.php?order_ref=" . urlencode($order['order_ref']);
        $cancelUrl = "{$appUrl}/cancel.php?order_ref=" . urlencode($order['order_ref']);

        $result = $apiClient->placeOrderWithToken([
            'external_ref' => $order['order_ref'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'product_code' => $order['product_code'],
            'product_name' => $order['product_name'],
            'ees_token' => $eesToken,
            'billing' => $billing,
            'customer_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl
        ]);

        $pdo = Database::getConnection();

        if ($result['success']) {
            $newStatus = !empty($result['redirect_url']) ? '3DS_PENDING' : 'AUTHORIZED';

            $stmt = $pdo->prepare("
                UPDATE orders 
                SET status = :status, tco_refno = :tco_refno, updated_at = CURRENT_TIMESTAMP
                WHERE order_ref = :order_ref
            ");
            $stmt->execute([
                ':status' => $newStatus,
                ':tco_refno' => $result['refno'],
                ':order_ref' => $order['order_ref']
            ]);

            // Record transaction log
            $tStmt = $pdo->prepare("
                INSERT INTO transactions (order_ref, tco_refno, type, amount, currency, status, payload)
                VALUES (:order_ref, :tco_refno, 'CHARGE', :amount, :currency, :status, :payload)
            ");
            $tStmt->execute([
                ':order_ref' => $order['order_ref'],
                ':tco_refno' => $result['refno'],
                ':amount' => $order['amount'],
                ':currency' => $order['currency'],
                ':status' => $newStatus,
                ':payload' => json_encode($result['raw'])
            ]);
        } else {
            // Update order as failed
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET status = 'FAILED', updated_at = CURRENT_TIMESTAMP
                WHERE order_ref = :order_ref
            ");
            $stmt->execute([':order_ref' => $order['order_ref']]);

            $tStmt = $pdo->prepare("
                INSERT INTO transactions (order_ref, tco_refno, type, amount, currency, status, payload)
                VALUES (:order_ref, NULL, 'FAILED_ATTEMPT', :amount, :currency, 'FAILED', :payload)
            ");
            $tStmt->execute([
                ':order_ref' => $order['order_ref'],
                ':amount' => $order['amount'],
                ':currency' => $order['currency'],
                ':payload' => json_encode($result['raw'] ?? ['error' => $result['error']])
            ]);
        }

        return $result;
    }

    /**
     * Process verified IPN webhook event with idempotency protection
     */
    public static function handleIpn(array $payload): bool
    {
        $refno = (string) ($payload['REFNO'] ?? '');
        $orderNo = (string) ($payload['ORDERNO'] ?? '');
        $ipnStatus = strtoupper((string) ($payload['ORDERSTATUS'] ?? ''));
        $externalRef = (string) ($payload['REFNOEXT'] ?? '');

        if (empty($refno)) {
            Logger::error("IPN rejected: Missing REFNO");
            return false;
        }

        $pdo = Database::getConnection();

        // 1. Idempotency Check: Has this exact event already been processed?
        $checkStmt = $pdo->prepare("
            SELECT id FROM ipn_events WHERE ipn_refno = :refno AND order_status = :status LIMIT 1
        ");
        $checkStmt->execute([
            ':refno' => $refno,
            ':status' => $ipnStatus
        ]);

        if ($checkStmt->fetch()) {
            Logger::notice("IPN idempotent replay skipped", [
                'refno' => $refno,
                'status' => $ipnStatus
            ]);
            return true; // Already processed, return success so read receipt can be generated
        }

        // 2. Map 2Checkout status to internal order status
        $internalStatus = match ($ipnStatus) {
            'COMPLETE', 'PAYMENT_RECEIVED' => 'COMPLETED',
            'AUTHRECEIVED' => 'AUTHORIZED',
            'PENDING', 'PENDING_APPROVAL' => 'PENDING',
            'REFUND', 'PAYMENT_REFUNDED' => 'REFUNDED',
            'CHARGEBACK', 'DISPUTED' => 'DISPUTED',
            default => 'PROCESSING'
        };

        // 3. Find matching order by external reference or tco_refno
        $order = null;
        if (!empty($externalRef)) {
            $order = self::getOrderByRef($externalRef);
        }
        if (!$order && !empty($refno)) {
            $order = self::getOrderByTcoRefno($refno);
        }

        // 4. Update order if found
        if ($order) {
            // Price & Currency Integrity check on webhook
            $incomingTotal = isset($payload['IPN_TOTALGENERAL']) ? (float)$payload['IPN_TOTALGENERAL'] : null;
            if ($incomingTotal !== null && abs($incomingTotal - (float)$order['amount']) > 0.01) {
                Logger::security("IPN amount mismatch alert", [
                    'order_ref' => $order['order_ref'],
                    'expected' => $order['amount'],
                    'received' => $incomingTotal
                ]);
            }

            $uStmt = $pdo->prepare("
                UPDATE orders 
                SET status = :status, tco_refno = :refno, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $uStmt->execute([
                ':status' => $internalStatus,
                ':refno' => $refno,
                ':id' => $order['id']
            ]);

            $tStmt = $pdo->prepare("
                INSERT INTO transactions (order_ref, tco_refno, type, amount, currency, status, payload)
                VALUES (:order_ref, :tco_refno, :type, :amount, :currency, :status, :payload)
            ");
            $tStmt->execute([
                ':order_ref' => $order['order_ref'],
                ':tco_refno' => $refno,
                ':type' => 'IPN_' . $ipnStatus,
                ':amount' => $order['amount'],
                ':currency' => $order['currency'],
                ':status' => $internalStatus,
                ':payload' => json_encode($payload)
            ]);

            Logger::info("Order updated via verified IPN", [
                'order_ref' => $order['order_ref'],
                'tco_refno' => $refno,
                'status' => $internalStatus
            ]);
        } else {
            Logger::warning("IPN received for untracked order", [
                'refno' => $refno,
                'external_ref' => $externalRef
            ]);
        }

        // 5. Store IPN event in log for permanent idempotency
        $eStmt = $pdo->prepare("
            INSERT INTO ipn_events (ipn_refno, ipn_orderno, order_status, payload)
            VALUES (:refno, :orderno, :status, :payload)
        ");
        $eStmt->execute([
            ':refno' => $refno,
            ':orderno' => $orderNo,
            ':status' => $ipnStatus,
            ':payload' => json_encode($payload)
        ]);

        return true;
    }
}
