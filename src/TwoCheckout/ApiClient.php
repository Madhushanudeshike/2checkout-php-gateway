<?php
declare(strict_types=1);

namespace App\TwoCheckout;

use App\Config;
use App\Logger;

/**
 * Class ApiClient
 * Highly secure client for 2Checkout (Verifone) REST API v6.0.
 * Handles HMAC authenticated order placement with 2Pay.js tokens and 3DS redirects.
 */
class ApiClient
{
    private string $merchantCode;
    private string $secretKey;
    private bool $isSandbox;

    public function __construct(?string $merchantCode = null, ?string $secretKey = null, ?bool $isSandbox = null)
    {
        $this->merchantCode = $merchantCode ?? (string) Config::get('merchant_code');
        $this->secretKey = $secretKey ?? (string) Config::get('secret_key');
        $this->isSandbox = $isSandbox ?? Config::isSandbox();

        if (empty($this->merchantCode) || empty($this->secretKey)) {
            Logger::error("2Checkout API credentials missing. Please check your .env configuration.");
        }
    }

    /**
     * Get the base API endpoint URL
     */
    public function getBaseUrl(): string
    {
        return 'https://api.2checkout.com/rest/6.0';
    }

    /**
     * Generate 2Checkout REST API 6.0 HMAC Authentication Header
     *
     * Format: code="MERCHANT" date="Y-m-d H:i:s" hash="HMAC_MD5"
     */
    public function generateAuthHeader(string $date): string
    {
        $string = strlen($this->merchantCode) . $this->merchantCode . strlen($date) . $date;
        $hash = hash_hmac('md5', $string, $this->secretKey);

        return sprintf('code="%s" date="%s" hash="%s"', $this->merchantCode, $date, $hash);
    }

    /**
     * Place order using 2Pay.js token
     *
     * @param array $order
     *   - external_ref: string
     *   - amount: float
     *   - currency: string
     *   - product_code: string
     *   - product_name: string
     *   - ees_token: string
     *   - billing: array [first_name, last_name, email, address, city, state, zip, country]
     *   - customer_ip: string
     *   - return_url: string
     *   - cancel_url: string
     *
     * @return array [success => bool, refno => string|null, redirect_url => string|null, error => string|null, raw => array]
     */
    public function placeOrderWithToken(array $order): array
    {
        $date = gmdate('Y-m-d H:i:s');
        $authHeader = $this->generateAuthHeader($date);

        $endpoint = $this->getBaseUrl() . '/orders/';

        $paymentType = $this->isSandbox ? 'TEST' : 'EES_TOKEN_PAYMENT';

        $payload = [
            'Country' => $order['billing']['country'] ?? 'US',
            'Currency' => $order['currency'],
            'Language' => 'en',
            'CustomerIP' => $order['customer_ip'] ?? '127.0.0.1',
            'ExternalReference' => $order['external_ref'],
            'Source' => 'API_PHP_CLIENT',
            'BillingDetails' => [
                'FirstName' => $order['billing']['first_name'],
                'LastName' => $order['billing']['last_name'],
                'Email' => $order['billing']['email'],
                'Address1' => $order['billing']['address'] ?? 'Street 1',
                'City' => $order['billing']['city'] ?? 'City',
                'State' => $order['billing']['state'] ?? 'State',
                'Zip' => $order['billing']['zip'] ?? '10001',
                'CountryCode' => $order['billing']['country'] ?? 'US'
            ],
            'Items' => [
                [
                    'Code' => $order['product_code'],
                    'Quantity' => 1,
                    'Price' => [
                        'Amount' => number_format((float) $order['amount'], 2, '.', ''),
                        'Type' => 'CUSTOM'
                    ]
                ]
            ],
            'PaymentDetails' => [
                'Type' => $paymentType,
                'Currency' => $order['currency'],
                'CustomerIP' => $order['customer_ip'] ?? '127.0.0.1',
                'PaymentMethod' => [
                    'EesToken' => $order['ees_token'],
                    'Vendor3DSReturnURL' => $order['return_url'],
                    'Vendor3DSCancelURL' => $order['cancel_url']
                ]
            ]
        ];

        Logger::info("Dispatching 2Checkout REST API order", [
            'external_ref' => $order['external_ref'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'product_code' => $order['product_code'],
            'payment_type' => $paymentType,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Avangate-Authentication: ' . $authHeader,
            ],
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error("cURL error contacting 2Checkout API", ['error' => $curlError]);
            return [
                'success' => false,
                'refno' => null,
                'redirect_url' => null,
                'error' => 'Communication error contacting payment processor.',
                'raw' => null
            ];
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            Logger::error("Invalid JSON response from 2Checkout API", ['http_code' => $httpCode, 'response' => $response]);
            return [
                'success' => false,
                'refno' => null,
                'redirect_url' => null,
                'error' => 'Unexpected response from payment processor.',
                'raw' => $response
            ];
        }

        // 200 OK or 201 Created indicates successful processing
        if ($httpCode === 200 || $httpCode === 201) {
            $refNo = $result['RefNo'] ?? ($result['OrderNo'] ?? null);
            $redirectUrl = $result['PaymentDetails']['PaymentMethod']['RedirectUrl'] ?? null;
            $status = $result['Status'] ?? 'AUTHRECEIVED';

            Logger::info("2Checkout order successful", [
                'refno' => $refNo,
                'has_3ds_redirect' => !empty($redirectUrl),
                'status' => $status
            ]);

            return [
                'success' => true,
                'refno' => (string) $refNo,
                'redirect_url' => $redirectUrl,
                'status' => $status,
                'error' => null,
                'raw' => $result
            ];
        }

        // Handle payment failure or decline
        $errorMessage = $result['message'] ?? ($result['Errors'][0]['Message'] ?? 'Payment authorization failed.');
        Logger::warning("2Checkout order rejected", [
            'http_code' => $httpCode,
            'message' => $errorMessage,
            'result' => $result
        ]);

        return [
            'success' => false,
            'refno' => null,
            'redirect_url' => null,
            'status' => 'FAILED',
            'error' => $errorMessage,
            'raw' => $result
        ];
    }
}
