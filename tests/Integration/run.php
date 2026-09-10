<?php
declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- CLI bootstrap configures PHPUnit and executes it.

require_once __DIR__ . '/bootstrap.php';

// Isolated PHPUnit workers must also load Omeka's vendor before the module's.
define('PHPUNIT_COMPOSER_INSTALL', __DIR__ . '/bootstrap.php');

exit((new PHPUnit\TextUI\Application())->run($_SERVER['argv']));
