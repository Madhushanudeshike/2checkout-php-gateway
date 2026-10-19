<?php
declare(strict_types=1);

namespace App\TwoCheckout;

use App\Config;
use App\Logger;

/**
 * Class IpnHandler
 * Cryptographically verifies 2Checkout (Verifone) Instant Payment Notifications (IPN).
 * Generates mandatory <EPAYMENT> read receipts to prevent retry storms.
 */
class IpnHandler
{
    private string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? (string) Config::get('ipn_secret_key');
        if (empty($this->secretKey)) {
            $this->secretKey = (string) Config::get('secret_key');
        }
    }

    /**
     * Recursively serializes the IPN payload by prefixing each value with its byte length.
     * Crucial: uses strlen() instead of mb_strlen() to match 2Checkout's raw byte count.
     */
    public function serializePayload(array $data): string
    {
        $result = '';
        foreach ($data as $key => $val) {
            // Exclude signature and hash fields used for verification
            if (in_array((string)$key, ['SIGNATURE_SHA2_256', 'SIGNATURE_SHA3_256', 'HASH'], true)) {
                continue;
            }

            if (is_array($val)) {
                $result .= $this->serializePayload($val);
            } else {
                $strVal = (string) $val;
                $result .= strlen($strVal) . $strVal;
            }
        }
        return $result;
    }

    /**
     * Validates incoming POST IPN request
     *
     * @param array $postData Raw $_POST array
     * @return bool True if signature is cryptographically valid
     */
    public function verifySignature(array $postData): bool
    {
        if (empty($this->secretKey)) {
            Logger::error("IPN verification failed: IPN Secret Key not configured");
            return false;
        }

        // 1. Check for SHA256 Signature (Modern standard)
        if (!empty($postData['SIGNATURE_SHA2_256'])) {
            $incomingSignature = (string) $postData['SIGNATURE_SHA2_256'];
            $serialized = $this->serializePayload($postData);
            $expectedSignature = hash_hmac('sha256', $serialized, $this->secretKey);

            $isValid = hash_equals($expectedSignature, $incomingSignature);
            if (!$isValid) {
                Logger::security("IPN HMAC-SHA256 signature mismatch", [
                    'refno' => $postData['REFNO'] ?? 'N/A',
                    'orderno' => $postData['ORDERNO'] ?? 'N/A',
                ]);
            }
            return $isValid;
        }

        // 2. Fallback to legacy MD5 HASH if configured on older 2Checkout accounts
        if (!empty($postData['HASH'])) {
            $incomingSignature = (string) $postData['HASH'];
            $serialized = $this->serializePayload($postData);
            $expectedSignature = hash_hmac('md5', $serialized, $this->secretKey);

            $isValid = hash_equals($expectedSignature, $incomingSignature);
            if (!$isValid) {
                Logger::security("IPN legacy MD5 hash mismatch", [
                    'refno' => $postData['REFNO'] ?? 'N/A',
                ]);
            }
            return $isValid;
        }

        Logger::security("IPN request rejected: No valid signature found in payload");
        return false;
    }

    /**
     * Generates the mandatory <EPAYMENT> confirmation receipt expected by 2Checkout.
     * Without this exact response, 2Checkout will assume failure and retry delivery repeatedly.
     */
    public function generateReceipt(array $postData): string
    {
        $responseDate = gmdate('YmdHis');

        $ipnProductId = '';
        if (isset($postData['IPN_PID'])) {
            $ipnProductId = is_array($postData['IPN_PID']) ? (string)($postData['IPN_PID'][0] ?? '') : (string)$postData['IPN_PID'];
        }

        $ipnProductName = '';
        if (isset($postData['IPN_PNAME'])) {
            $ipnProductName = is_array($postData['IPN_PNAME']) ? (string)($postData['IPN_PNAME'][0] ?? '') : (string)$postData['IPN_PNAME'];
        }

        $ipnDate = (string) ($postData['IPN_DATE'] ?? '');

        $receiptSource = strlen($ipnProductId) . $ipnProductId .
                        strlen($ipnProductName) . $ipnProductName .
                        strlen($ipnDate) . $ipnDate .
                        strlen($responseDate) . $responseDate;

        // Use SHA256 if modern signature was present, otherwise MD5
        if (!empty($postData['SIGNATURE_SHA2_256'])) {
            $receiptHash = hash_hmac('sha256', $receiptSource, $this->secretKey);
        } else {
            $receiptHash = hash_hmac('md5', $receiptSource, $this->secretKey);
        }

        return "<EPAYMENT>{$responseDate}|{$receiptHash}</EPAYMENT>";
    }
}
