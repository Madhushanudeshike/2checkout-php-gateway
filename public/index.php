<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Config;
use App\OrderService;
use App\Security;

// Apply production security headers
Security::sendSecurityHeaders();

// Generate CSRF token
$csrfToken = Security::generateCsrfToken();
$merchantCode = Config::get('merchant_code');
$isSandbox = Config::isSandbox();
$products = OrderService::$products;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Secure 2Checkout Payment Gateway</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="css/style.css">

    <!-- 2Checkout 2Pay.js Official SDK -->
    <script type="text/javascript" src="https://2pay-js.2checkout.com/v1/2pay.js"></script>
    <script>
        window.TCO_CONFIG = {
            merchantCode: <?= json_encode($merchantCode) ?>,
            isSandbox: <?= json_encode($isSandbox) ?>
        };
    </script>
</head>
<body>

    <div class="checkout-container">
        <header class="checkout-header">
            <div class="badge-secure">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                PCI-DSS Level 1 &bull; 256-Bit Encrypted
            </div>
            <h1>High-Security Payment Gateway</h1>
            <p>Direct tokenization via Verifone / 2Checkout with Zero Server Cardholder Exposure</p>
        </header>

        <div id="checkout-alert" class="alert"></div>

        <div class="checkout-grid">
            <!-- Payment Form Section -->
            <section class="card">
                <h2 class="card-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                    Payment Details
                </h2>

                <form id="payment-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">

                    <!-- Product Plan Selection -->
                    <div class="plan-picker">
                        <?php 
                        $first = true;
                        foreach ($products as $code => $prod): 
                            $activeClass = $first ? 'active' : '';
                        ?>
                            <label class="plan-option <?= $activeClass ?>" data-code="<?= Security::e($code) ?>" data-name="<?= Security::e($prod['name']) ?>" data-price="<?= number_format($prod['amount'], 2) ?>">
                                <input type="radio" name="product_code" value="<?= Security::e($code) ?>" <?= $first ? 'checked' : '' ?>>
                                <div class="plan-info">
                                    <h4><?= Security::e($prod['name']) ?></h4>
                                    <p><?= Security::e($prod['description']) ?></p>
                                </div>
                                <div class="plan-price">
                                    $<?= number_format($prod['amount'], 2) ?> <span>USD</span>
                                </div>
                            </label>
                        <?php 
                            $first = false;
                        endforeach; 
                        ?>
                    </div>

                    <!-- Customer Billing Details -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" class="form-control" placeholder="Jane" required value="Jane">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" class="form-control" placeholder="Doe" required value="Doe">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" class="form-control" placeholder="jane.doe@example.com" required value="jane.doe@example.com">
                        </div>
                        <div class="form-group">
                            <label for="country">Country *</label>
                            <select id="country" name="country" class="form-control" required>
                                <option value="US" selected>United States (US)</option>
                                <option value="CA">Canada (CA)</option>
                                <option value="GB">United Kingdom (GB)</option>
                                <option value="AU">Australia (AU)</option>
                                <option value="DE">Germany (DE)</option>
                                <option value="FR">France (FR)</option>
                                <option value="IN">India (IN)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Billing Address</label>
                        <input type="text" id="address" name="address" class="form-control" placeholder="123 Financial Way" value="123 Financial Way">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" class="form-control" placeholder="Columbus" value="Columbus">
                        </div>
                        <div class="form-group">
                            <label for="state">State / Province</label>
                            <input type="text" id="state" name="state" class="form-control" placeholder="OH" value="OH">
                        </div>
                        <div class="form-group">
                            <label for="zip">Zip / Postal Code</label>
                            <input type="text" id="zip" name="zip" class="form-control" placeholder="43215" value="43215">
                        </div>
                    </div>

                    <!-- 2Checkout Secure Card Element (Tokenized inside 2Pay.js iframe) -->
                    <div class="form-group">
                        <label>Credit / Debit Card (Powered by 2Pay.js) *</label>
                        <div class="card-element-wrapper">
                            <div id="card-element">
                                <!-- Secure 2Pay.js iframe mounts here -->
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="submit-btn" class="btn-pay">
                        <span class="spinner" id="btn-spinner"></span>
                        <span id="btn-text">Pay Now</span>
                    </button>
                </form>
            </section>

            <!-- Order Summary Sidebar -->
            <aside class="card">
                <h2 class="card-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                    Order Summary
                </h2>

                <?php 
                $firstProd = reset($products);
                $firstCode = key($products);
                ?>
                <div class="summary-item">
                    <span>Selected Plan:</span>
                    <strong id="summary-product-name"><?= Security::e($firstProd['name']) ?></strong>
                </div>

                <div class="summary-item">
                    <span>Subtotal:</span>
                    <span id="summary-subtotal">$<?= number_format($firstProd['amount'], 2) ?> USD</span>
                </div>

                <div class="summary-item">
                    <span>Processing & Tax:</span>
                    <span>$0.00 USD</span>
                </div>

                <div class="summary-item total">
                    <span>Total Due:</span>
                    <span id="summary-total">$<?= number_format($firstProd['amount'], 2) ?> USD</span>
                </div>

                <div class="security-footer">
                    <p>&bull; <strong>PCI-DSS SAQ-A Compliant:</strong> Cardholder data never touches this server.</p>
                    <p>&bull; <strong>3D Secure 2.0:</strong> Automatic Strong Customer Authentication (SCA) verification.</p>
                    <p>&bull; <strong>Replay &amp; CSRF Defense:</strong> Cryptographic token verification on all transactions.</p>
                    <div class="security-badges">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span>2Checkout Verified Merchant</span>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <footer>
        &copy; <?= date('Y') ?> Secure Payment Gateway Architecture &bull; Powered by Verifone / 2Checkout API v6.0
    </footer>

    <script src="js/checkout.js"></script>
</body>
</html>
