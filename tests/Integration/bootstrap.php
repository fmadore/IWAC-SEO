<?php
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);
$omekaRoot = rtrim((string) getenv('OMEKA_PATH'), "\\/");
$omekaAutoload = $omekaRoot . '/vendor/autoload.php';
$moduleAutoload = $moduleRoot . '/vendor/autoload.php';

if ($omekaRoot === '' || !is_readable($omekaAutoload)) {
    throw new RuntimeException(
        'Set OMEKA_PATH to an installed Omeka S tree before running the integration suite.'
    );
}
if (!is_readable($moduleAutoload)) {
    throw new RuntimeException('Run composer install in the IWAC SEO module first.');
}

// Omeka owns Laminas and PSR at runtime. Its autoloader must therefore win the
// framework boundary; the module vendor contains development tools only.
require_once $omekaAutoload;
require_once $moduleAutoload;

if (!class_exists(Omeka\Api\Representation\ItemRepresentation::class)) {
    throw new RuntimeException('OMEKA_PATH did not provide the Omeka S application classes.');
}
if (!class_exists(Laminas\View\Renderer\PhpRenderer::class)) {
    throw new RuntimeException('OMEKA_PATH did not provide the Laminas framework classes.');
}
