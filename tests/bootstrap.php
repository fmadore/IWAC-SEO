<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * The module has no bundled vendor/ and Omeka S is not a Composer dependency,
 * so the suite runs against the module alone. Classes the module type-hints but
 * only *reads* through a narrow surface (a settings store, a job dispatcher)
 * get a minimal shim here, which is what lets the settings gate, the ping queue
 * and the sitemap cache be tested at all.
 *
 * Every shim is guarded by class_exists(), so if the suite is ever run from
 * inside a real Omeka installation the real classes win and nothing is faked.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Shim/omeka.php';
