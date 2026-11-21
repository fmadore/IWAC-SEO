<?php
declare(strict_types=1);

/**
 * PHPStan analyses the module against the real Omeka S application: the
 * classes it extends, the representations it reads, the Laminas framework
 * Omeka bundles. OMEKA_PATH names that tree, as for the integration suite
 * (CLAUDE.md gives the local checkout; CI unpacks the verified release zip).
 * Omeka's autoloader is registered first, so it owns Laminas and PSR, as at
 * runtime; the module's own vendor/ holds development tools only.
 */

$omekaRoot = rtrim((string) getenv('OMEKA_PATH'), "\\/");
$omekaAutoload = $omekaRoot . '/vendor/autoload.php';
if ($omekaRoot === '' || !is_readable($omekaAutoload)) {
    fwrite(STDERR, "Set OMEKA_PATH to an Omeka S tree before running PHPStan (see CLAUDE.md).\n");
    exit(1);
}
require_once $omekaAutoload;
