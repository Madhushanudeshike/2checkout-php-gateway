<?php
declare(strict_types=1);

/**
 * Global Bootstrap Configuration
 */

// Define base root path
define('BASE_PATH', dirname(__DIR__));

// Custom PSR-4 compliant autoloader for App namespace
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Load environment configuration
\App\Config::load(BASE_PATH);

// Initialize secure audit logger
\App\Logger::init(BASE_PATH);

// Setup secure error and exception handling
if (\App\Config::get('app_debug', false)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// Global exception handler to log errors securely and present friendly error
set_exception_handler(function (\Throwable $e) {
    \App\Logger::error("Uncaught exception: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (\App\Config::get('app_debug', false)) {
        echo "<h1>Internal Server Error</h1>";
        echo "<pre>" . \App\Security::e($e->getMessage()) . "</pre>";
    } else {
        echo "<!DOCTYPE html><html><head><title>System Error</title><meta charset='utf-8'></head><body style='font-family:sans-serif;text-align:center;padding:50px;'>";
        echo "<h2>Payment Gateway Temporarily Unavailable</h2>";
        echo "<p>An internal error occurred. Our technical security team has been notified. Please try again later.</p>";
        echo "</body></html>";
    }
    exit;
});
