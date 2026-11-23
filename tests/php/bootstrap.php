<?php
/** Bootstrap PHPUnit: autoload composer bila ada, jika tidak require manual. */
$root = dirname(__DIR__, 2);
$vendor = $root . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}
require_once $root . '/config/config.php';
