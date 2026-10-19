<?php
declare(strict_types=1);

namespace App;

/**
 * Class Security
 * Core security utilities: CSRF defense, HTTP hardening headers, Rate Limiting, Input Validation.
 */
class Security
{
    /**
     * Start secure session with cookie security flags
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       ($_SERVER['SERVER_PORT'] ?? 80) == 443;

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            session_start();
        }
    }

    /**
     * Set production-grade HTTP security headers
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Content Security Policy
        // Permitting 2Checkout scripts, fonts, and inline styles needed for 2Pay.js iframe
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://2pay-js.2checkout.com https://secure.2checkout.com https://cdnjs.cloudflare.com; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com data:; " .
               "frame-src 'self' https://2pay-js.2checkout.com https://secure.2checkout.com https://sandbox.2checkout.com; " .
               "connect-src 'self' https://api.2checkout.com https://sandbox.2checkout.com https://2pay-js.2checkout.com; " .
               "img-src 'self' data: https:; " .
               "base-uri 'self'; " .
               "form-action 'self' https://secure.2checkout.com https://sandbox.2checkout.com;";

        header("Content-Security-Policy: {$csp}");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self 'https://2pay-js.2checkout.com')");

        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }
    }

    /**
     * Generate or fetch CSRF token with timestamp
     */
    public static function generateCsrfToken(): string
    {
        self::startSecureSession();

        $lifetime = (int) Config::get('csrf_lifetime', 3600);
        $now = time();

        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            ($now - (int)$_SESSION['csrf_token_time'] > $lifetime)
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = $now;
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validate submitted CSRF token using constant-time string comparison
     */
    public static function validateCsrfToken(?string $token): bool
    {
        self::startSecureSession();

        if (empty($token) || empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
            Logger::security('CSRF token validation failed: Missing token in session or request');
            return false;
        }

        $lifetime = (int) Config::get('csrf_lifetime', 3600);
        if (time() - (int)$_SESSION['csrf_token_time'] > $lifetime) {
            Logger::security('CSRF token expired');
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }

        $isValid = hash_equals($_SESSION['csrf_token'], $token);
        if (!$isValid) {
            Logger::security('CSRF token mismatch detected');
        }

        return $isValid;
    }

    /**
     * IP-based rate limiting to prevent automated card stuffing attacks
     */
    public static function checkRateLimit(string $action = 'checkout'): bool
    {
        $maxAttempts = (int) Config::get('rate_limit_attempts', 10);
        $windowSeconds = (int) Config::get('rate_limit_window', 60);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Hash IP for privacy in file system
        $ipHash = hash('sha256', $ip . $action);
        $rateDir = Config::get('base_dir') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limits';

        if (!is_dir($rateDir)) {
            @mkdir($rateDir, 0750, true);
        }

        $rateFile = $rateDir . DIRECTORY_SEPARATOR . "rate_{$ipHash}.json";
        $now = time();
        $attempts = [];

        if (file_exists($rateFile)) {
            $data = json_decode((string) file_get_contents($rateFile), true);
            if (is_array($data)) {
                // Filter timestamps within current window
                $attempts = array_filter($data, fn($ts) => ($now - $ts) < $windowSeconds);
            }
        }

        if (count($attempts) >= $maxAttempts) {
            Logger::security("Rate limit exceeded for IP {$ip} on action '{$action}'", [
                'attempts' => count($attempts),
                'max' => $maxAttempts,
                'window' => $windowSeconds
            ]);
            return false;
        }

        $attempts[] = $now;
        @file_put_contents($rateFile, json_encode(array_values($attempts)), LOCK_EX);
        return true;
    }

    /**
     * Sanitize string for HTML output (prevents XSS)
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Validate customer email format
     */
    public static function validateEmail(string $email): ?string
    {
        $cleaned = trim($email);
        if (filter_var($cleaned, FILTER_VALIDATE_EMAIL)) {
            return strtolower($cleaned);
        }
        return null;
    }

    /**
     * Validate ISO 3166-1 alpha-2 country code
     */
    public static function validateCountryCode(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{2}$/', $code)) {
            return $code;
        }
        return null;
    }

    /**
     * Validate ISO 4217 currency code
     */
    public static function validateCurrency(string $currency): ?string
    {
        $currency = strtoupper(trim($currency));
        $supported = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'INR', 'SGD', 'CHF'];
        if (in_array($currency, $supported, true)) {
            return $currency;
        }
        return null;
    }

    /**
     * Validate monetary amount
     */
    public static function validateAmount($amount): ?float
    {
        if (is_numeric($amount)) {
            $float = (float) $amount;
            if ($float > 0.00 && $float <= 1000000.00) {
                return round($float, 2);
            }
        }
        return null;
    }
}
