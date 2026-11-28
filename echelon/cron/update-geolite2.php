<?php
/**
 * Updates the GeoLite2 database for the World Map from the command line - for cron (Linux) or the
 * Task Scheduler (Windows). It uses the MaxMind details saved under Settings > Maps & World Map.
 *
 *   php /path/to/echelon/cron/update-geolite2.php            download only if the database is more than 3 days old
 *   php /path/to/echelon/cron/update-geolite2.php --force    always download
 *
 * Run it as the web server's user (for example www-data) so the file keeps the right owner.
 * MaxMind publishes on Tuesdays and Fridays; twice a week is plenty.
 * Exit code: 0 = updated or nothing to do, 1 = failed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is run from the command line.');
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$force = in_array('--force', $argv ?? array(), true);

require_once $root . 'inc/config.php';
require_once $root . 'inc/defaults.php';
if (!defined('INSTALLED') || INSTALLED !== 'yes') { fwrite(STDERR, "Echelon is not installed yet.\n"); exit(1); }
require_once $root . 'inc/functions.php';
require_once $root . 'inc/geo.php';
require_once $root . 'classes/dbl-class.php';

try {
    $dbl = DBL::getInstance();
    $config = array('cosmos' => $dbl->getSettings());
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot reach the Echelon database: ' . $e->getMessage() . "\n");
    exit(1);
}

if ((string) geoCfg('maxmind_account', '') === '' || (string) geoCfg('maxmind_key', '') === '') {
    fwrite(STDERR, "No MaxMind account details are saved yet. Set them up under Settings > Maps & World Map first.\n");
    exit(1);
}

$last = geoLastUpdated();
if (!$force && $last > 0 && (time() - $last) <= geoFreshSeconds()) {
    echo 'GeoLite2 database is up to date (downloaded ' . gmdate('Y-m-d H:i', $last) . " UTC). Nothing to do.\n";
    exit(0);
}

list($ok, $msg) = geoUpdateNow();
if ($ok) {
    $i = geoMmdbInfo();
    echo $msg . ($i ? ' Database built ' . gmdate('Y-m-d', $i['built']) . ', ' . round($i['size'] / 1048576, 1) . " MB.\n" : "\n");
    exit(0);
}
fwrite(STDERR, 'Update failed: ' . $msg . "\n");
exit(1);
