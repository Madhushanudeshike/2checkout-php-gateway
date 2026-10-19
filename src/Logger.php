<?php
declare(strict_types=1);

namespace App;

/**
 * Class Logger
 * Secure transaction and audit logger with automated sensitive data redaction.
 */
class Logger
{
    private static ?string $logDir = null;

    private static array $redactKeys = [
        'cvv', 'cvv2', 'card_number', 'cc_num', 'cardnumber', 'pan', 'ees_token',
        'token', 'secret_key', 'secret_word', 'ipn_secret_key', 'password', 'pass', 'hash'
    ];

    public static function init(string $baseDir): void
    {
        self::$logDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log('NOTICE', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        self::log('SECURITY_ALERT', $message, $context);
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        if (self::$logDir === null) {
            self::init(dirname(__DIR__));
        }

        $date = gmdate('Y-m-d');
        $logFile = self::$logDir . DIRECTORY_SEPARATOR . "payment_{$date}.log";

        $sanitizedContext = self::redactSensitiveData($context);
        $timestamp = gmdate('Y-m-d\TH:i:s.u\Z');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? substr(bin2hex(random_bytes(8)), 0, 12);

        $entry = sprintf(
            "[%s] [%s] [REQ:%s] [IP:%s] %s | Context: %s\n",
            $timestamp,
            str_pad($level, 14),
            $requestId,
            $ip,
            $message,
            json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Recursively redacts sensitive payment details, tokens, and credentials
     */
    public static function redactSensitiveData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $val) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::$redactKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($val)) {
                $sanitized[$key] = self::redactSensitiveData($val);
            } elseif (is_string($val)) {
                // Check if string looks like 16 digit PAN
                if (preg_match('/\b(?:\d[ -]*?){13,19}\b/', $val)) {
                    $sanitized[$key] = '[MASKED_PAN]';
                } else {
                    $sanitized[$key] = $val;
                }
            } else {
                $sanitized[$key] = $val;
            }
        }
        return $sanitized;
    }
}
