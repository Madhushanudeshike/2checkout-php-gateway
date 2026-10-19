<?php
declare(strict_types=1);

namespace App;

/**
 * Class Config
 * Secure configuration reader and environment manager.
 */
class Config
{
    private static array $settings = [];
    private static bool $loaded = false;

    /**
     * Load environment variables from .env file
     */
    public static function load(string $baseDir): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) {
                        continue;
                    }
                    if (strpos($line, '=') !== false) {
                        [$name, $value] = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value);

                        // Strip outer quotes if present
                        if (
                            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))
                        ) {
                            $value = substr($value, 1, -1);
                        }

                        putenv("{$name}={$value}");
                        $_ENV[$name] = $value;
                        $_SERVER[$name] = $value;
                    }
                }
            }
        }

        self::$settings = [
            'env' => getenv('TCO_ENV') ?: 'sandbox',
            'merchant_code' => getenv('TCO_MERCHANT_CODE') ?: '',
            'secret_key' => getenv('TCO_SECRET_KEY') ?: '',
            'secret_word' => getenv('TCO_SECRET_WORD') ?: '',
            'ipn_secret_key' => getenv('TCO_IPN_SECRET_KEY') ?: (getenv('TCO_SECRET_KEY') ?: ''),
            'app_url' => rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/'),
            'app_debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),
            'db_driver' => getenv('DB_DRIVER') ?: 'sqlite',
            'db_sqlite_path' => getenv('DB_SQLITE_PATH') ?: 'storage/database.sqlite',
            'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
            'db_port' => (int) (getenv('DB_PORT') ?: 3306),
            'db_name' => getenv('DB_NAME') ?: 'payment_gateway',
            'db_user' => getenv('DB_USER') ?: 'root',
            'db_pass' => getenv('DB_PASS') ?: '',
            'csrf_lifetime' => (int) (getenv('CSRF_LIFETIME') ?: 3600),
            'rate_limit_attempts' => (int) (getenv('RATE_LIMIT_CHECKOUT_ATTEMPTS') ?: 10),
            'rate_limit_window' => (int) (getenv('RATE_LIMIT_CHECKOUT_WINDOW') ?: 60),
            'base_dir' => rtrim($baseDir, '/\\'),
        ];

        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        return self::$settings[$key] ?? $default;
    }

    public static function isSandbox(): bool
    {
        return strtolower((string) self::get('env')) === 'sandbox';
    }

    public static function all(): array
    {
        return self::$settings;
    }
}
