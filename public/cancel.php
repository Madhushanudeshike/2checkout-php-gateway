<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\OrderService;
use App\Security;

Security::sendSecurityHeaders();

$orderRef = $_GET['order_ref'] ?? null;
if (!empty($orderRef)) {
    $order = OrderService::getOrderByRef((string)$orderRef);
    if ($order && $order['status'] === 'PENDING') {
        // Can optionally mark as cancelled in database
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare("UPDATE orders SET status = 'CANCELLED' WHERE id = :id");
        $stmt->execute([':id' => $order['id']]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .cancel-card {
            max-width: 580px;
            margin: 60px auto;
            text-align: center;
        }
        .icon-cancel {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 2px solid rgba(239, 68, 68, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

    <div class="checkout-container">
        <div class="card cancel-card">
            <div class="icon-cancel">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </div>
            <h2>Transaction Cancelled</h2>
            <p style="color: var(--text-muted); margin: 12px 0 28px;">
                You cancelled the checkout process. Your card was not charged. You can return to try again whenever you're ready.
            </p>
            <a href="index.php" class="btn-pay" style="text-decoration: none; display: inline-flex; width: auto; padding: 12px 30px;">
                Return to Checkout
            </a>
        </div>
    </div>

</body>
</html>
