<?php
/**
 * Echelon runtime defaults and error policy.
 *
 * Loaded by inc.php right after inc/config.php. Everything here is a *fallback*:
 * anything already defined in config.php wins. This keeps installs whose
 * generated config predates a setting from hitting "undefined constant" fatals.
 */

if (PHP_VERSION_ID < 80100) {
    die('Echelon requires PHP 8.1 or newer. This server is running PHP ' . PHP_VERSION . '.');
}

## Filesystem root of the echelon directory (works under Apache, nginx+FPM and php -S alike;
## getenv("DOCUMENT_ROOT") is empty on many FastCGI setups)
if (!defined('ECH_ROOT')) {
    define('ECH_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

## Set ECH_DEBUG to true in inc/config.php to show errors on screen (never leave on in production)
if (!defined('ECH_DEBUG')) {
    define('ECH_DEBUG', false);
}

## Log file location
if (!defined('ECH_LOG')) {
    define('ECH_LOG', ECH_ROOT . 'lib/log.txt');
}

## Optional integrations
if (!defined('PROXYCHECKioKEY')) {
    define('PROXYCHECKioKEY', '');
}
if (!isset($getipintel_email)) {
    $getipintel_email = '';
}
if (!isset($email_config) || !is_array($email_config)) {
    $email_config = array('email_enable' => false, 'admin_email' => '', 'board_email' => '');
}

## Echelon version. Owned by the code (not inc/config.php) so an upgrade can never leave an old number behind.
if (!defined('ECH_VERSION')) {
    define('ECH_VERSION', 'v3.0');
}

## Directory where uploaded map thumbnails live (web path is PATH . 'images/maps/')
if (!defined('MAP_IMG_DIR')) {
    define('MAP_IMG_DIR', ECH_ROOT . 'images/maps/');
}

## ---- Error policy -------------------------------------------------------------
## PHP 8 turned undefined variables / array keys into warnings. We report everything
## to the Echelon log, and only show it on screen when ECH_DEBUG is on.
error_reporting(E_ALL);
ini_set('display_errors', ECH_DEBUG ? '1' : '0');
ini_set('log_errors', '0'); // we write our own log below

## mysqli: throw exceptions (the PHP 8.1 default) - made explicit so php.ini cannot change behaviour
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('echelonErrorHandler')) {
    function echelonErrorHandler(int $no, string $str, string $file, int $line): bool {
        if (!(error_reporting() & $no)) {
            return false; // @-suppressed
        }
        // Deprecations are noise for site admins - only record them when debugging
        if (($no & (E_DEPRECATED | E_USER_DEPRECATED)) && !ECH_DEBUG) {
            return true;
        }
        static $count = 0;
        if (++$count <= 50) { // never let one runaway loop fill the disk
            echLog('warning', $str . ' in ' . str_replace(ECH_ROOT, '', $file) . ':' . $line);
        }
        return ECH_DEBUG ? false : true; // false = let PHP also print it when debugging
    }

    function echelonExceptionHandler(Throwable $e): void {
        echLog('error', get_class($e) . ': ' . $e->getMessage() . ' in ' . str_replace(ECH_ROOT, '', $e->getFile()) . ':' . $e->getLine(), $e->getCode(), $e->getTraceAsString());
        if (!headers_sent()) {
            http_response_code(500);
        }
        if (ECH_DEBUG) {
            echo '<pre style="white-space:pre-wrap">' . htmlspecialchars((string) $e) . '</pre>';
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Echelon error</title>'
               . '<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:12vh auto;padding:0 1rem">'
               . '<h2>Something went wrong</h2><p>Echelon hit an unexpected error and it has been written to the log. '
               . 'Please go back and try again, or ask a site admin to check <code>lib/log.txt</code>.</p></body>';
        }
        exit(1);
    }

    set_error_handler('echelonErrorHandler');
    set_exception_handler('echelonExceptionHandler');
}

// "no games configured yet" flag, set to true by inc/setup.php when the games table is empty
if (!isset($no_games)) { $no_games = false; }
if (!isset($b3_conn)) { $b3_conn = false; } // pages/actions that do not need the B3 database simply leave it out
