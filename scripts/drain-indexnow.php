<?php
declare(strict_types=1);

// Cron entry point: */5 * * * * php /path/to/modules/IwacSeo/scripts/drain-indexnow.php
$omeka = getenv('OMEKA_PATH') ?: dirname(__DIR__, 3);
$omeka = realpath($omeka) ?: $omeka;
if (!is_readable($omeka . '/bootstrap.php')) {
    fwrite(STDERR, "Set OMEKA_PATH to the Omeka installation.\n");
    exit(2);
}
require $omeka . '/bootstrap.php';
$application = \Omeka\Mvc\Application::init(require $omeka . '/application/config/application.config.php');
$services = $application->getServiceManager();
if (in_array('--retry-failed', $argv, true)) {
    (new \IwacSeo\Service\PingRepository($services->get('Omeka\Connection')))->retryFailed();
}
if (!$services->get(\IwacSeo\Service\PingQueue::class)->isEnabled()) {
    exit(0);
}
$services->get('Omeka\Job\Dispatcher')->dispatch(\IwacSeo\Job\PingSearchEngines::class, null, $services->get('Omeka\Job\DispatchStrategy\Synchronous'));
