<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Logger;
use App\OrderService;
use App\Security;
use App\TwoCheckout\ReturnValidator;

Security::sendSecurityHeaders();

$orderRef = $_GET['order_ref'] ?? ($_GET['order-ext-ref'] ?? null);
$refno = $_GET['refno'] ?? null;
$signature = $_GET['signature'] ?? null;

$isSignatureValid = true;
if (!empty($signature)) {
    $validator = new ReturnValidator();
    $isSignatureValid = $validator->validate($_GET);
    if (!$isSignatureValid) {
        Logger::security("Tampered return URL parameters detected", $_GET);
    }
}

// Fetch order details
$order = null;
if (!empty($orderRef)) {
    $order = OrderService::getOrderByRef((string)$orderRef);
} elseif (!empty($refno)) {
    $order = OrderService::getOrderByTcoRefno((string)$refno);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmation &bull; 2Checkout Gateway</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .confirmation-card {
            max-width: 650px;
            margin: 60px auto;
            text-align: center;
        }
        .icon-status {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
        .icon-success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 2px solid rgba(16, 185, 129, 0.3);
        }
        .icon-warning {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 2px solid rgba(239, 68, 68, 0.3);
        }
        .details-table {
            width: 100%;
            margin: 25px 0;
            border-collapse: collapse;
            text-align: left;
        }
        .details-table tr {
            border-bottom: 1px solid var(--surface-border);
        }
        .details-table td {
            padding: 12px 6px;
            font-size: 0.95rem;
        }
        .details-table td:first-child {
            color: var(--text-muted);
        }
        .details-table td:last-child {
            text-align: right;
            font-weight: 600;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-badge.AUTHORIZED, .status-badge.COMPLETED {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }
        .status-badge.PENDING, .status-badge.3DS_PENDING {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }
        .status-badge.FAILED {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }
    </style>
</head>
<body>

    <div class="checkout-container">
        <div class="card confirmation-card">
            <?php if (!$isSignatureValid): ?>
                <div class="icon-status icon-warning">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                </div>
                <h2>Security Alert: Signature Verification Failed</h2>
                <p style="color: var(--text-muted); margin-top: 10px;">
                    The return URL parameters could not be cryptographically verified. If you completed a payment, it will be automatically confirmed once the webhook is delivered.
                </p>
            <?php elseif ($order): ?>
                <div class="icon-status icon-success">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2>Payment Successful!</h2>
                <p style="color: var(--text-muted); margin-top: 6px;">
                    Thank you, <?= Security::e($order['customer_name']) ?>. Your transaction was processed securely.
                </p>

                <table class="details-table">
                    <tr>
                        <td>Order Reference</td>
                        <td><code><?= Security::e($order['order_ref']) ?></code></td>
                    </tr>
                    <?php if (!empty($order['tco_refno'])): ?>
                    <tr>
                        <td>2Checkout Ref No</td>
                        <td><code><?= Security::e($order['tco_refno']) ?></code></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Product / Service</td>
                        <td><?= Security::e($order['product_name']) ?></td>
                    </tr>
                    <tr>
                        <td>Total Amount Paid</td>
                        <td>$<?= number_format((float)$order['amount'], 2) ?> <?= Security::e($order['currency']) ?></td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td><span class="status-badge <?= Security::e($order['status']) ?>"><?= Security::e($order['status']) ?></span></td>
                    </tr>
                    <tr>
                        <td>Date</td>
                        <td><?= Security::e($order['created_at']) ?> UTC</td>
                    </tr>
                </table>

                <a href="index.php" class="btn-pay" style="text-decoration: none; display: inline-flex; width: auto; padding: 12px 28px;">
                    Return to Store
                </a>
            <?php else: ?>
                <div class="icon-status icon-warning">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h2>Order Pending Confirmation</h2>
                <p style="color: var(--text-muted); margin-top: 10px;">
                    Your payment was submitted and is undergoing processor verification. Once approved, you will receive confirmation by email.
                </p>
                <div style="margin-top: 25px;">
                    <a href="index.php" class="btn-pay" style="text-decoration: none; display: inline-flex; width: auto; padding: 12px 28px;">
                        Back to Home
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
