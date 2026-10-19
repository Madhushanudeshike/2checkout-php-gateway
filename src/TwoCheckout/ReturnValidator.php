<?php
declare(strict_types=1);

namespace App\TwoCheckout;

use App\Config;
use App\Logger;

/**
 * Class ReturnValidator
 * Validates 2Checkout redirect / return URL signatures (ConvertPlus, InLine, and 3DS callbacks).
 * Uses the Buy-Link Secret Word and SHA256 HMAC.
 */
class ReturnValidator
{
    private string $secretWord;

    public function __construct(?string $secretWord = null)
    {
        $this->secretWord = $secretWord ?? (string) Config::get('secret_word');
    }

    /**
     * Sorts parameters alphabetically (case-insensitive) and serializes with byte-length prefixes.
     */
    public function serializeParameters(array $params): string
    {
        // Sort keys alphabetically
        uksort($params, 'strcasecmp');

        $serialized = '';
        foreach ($params as $value) {
            if (is_array($value)) {
                $serialized .= $this->serializeParameters($value);
            } else {
                $valString = (string) $value;
                $serialized .= strlen($valString) . $valString;
            }
        }

        return $serialized;
    }

    /**
     * Validates incoming GET parameters against the provided signature
     *
     * @param array $queryParams Usually $_GET
     * @return bool
     */
    public function validate(array $queryParams): bool
    {
        if (empty($this->secretWord)) {
            Logger::error("Return URL validation failed: Buy-Link Secret Word is not configured.");
            return false;
        }

        $incomingSignature = $queryParams['signature'] ?? '';
        if (empty($incomingSignature)) {
            Logger::security("Return URL missing signature parameter");
            return false;
        }

        // Remove signature parameter from source array before computing hash
        $paramsToSign = $queryParams;
        unset($paramsToSign['signature']);

        $serializedData = $this->serializeParameters($paramsToSign);
        $calculatedSignature = hash_hmac('sha256', $serializedData, $this->secretWord);

        $isValid = hash_equals($calculatedSignature, (string)$incomingSignature);
        if (!$isValid) {
            // Also attempt MD5 fallback for legacy configured return links
            $md5Signature = hash_hmac('md5', $serializedData, $this->secretWord);
            if (hash_equals($md5Signature, (string)$incomingSignature)) {
                return true;
            }

            Logger::security("Return URL signature mismatch detected", [
                'refno' => $queryParams['refno'] ?? 'N/A',
                'order_ext_ref' => $queryParams['order-ext-ref'] ?? 'N/A'
            ]);
        }

        return $isValid;
    }
}
