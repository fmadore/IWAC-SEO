<?php
declare(strict_types=1);

// Cron entry point: */5 * * * * php /path/to/modules/IwacSeo/scripts/drain-indexnow.php
//
// The file ships inside modules/, which a PHP-FPM location block may execute on
// request. It is a command, never a page: refuse anything but the CLI.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$omeka = getenv('OMEKA_PATH') ?: dirname(__DIR__, 3);
$omeka = realpath($omeka) ?: $omeka;
if (!is_readable($omeka . '/bootstrap.php')) {
    fwrite(STDERR, "Set OMEKA_PATH to the Omeka installation.\n");
    exit(2);
}
require $omeka . '/bootstrap.php';
$application = \Omeka\Mvc\Application::init(require $omeka . '/application/config/application.config.php');
$services = $application->getServiceManager();
$repository = new \IwacSeo\Service\PingRepository($services->get('Omeka\Connection'));
if (in_array('--retry-failed', $argv, true)) {
    $repository->retryFailed();
}
// Dispatching records a job row even when there is nothing to send; at one
// run every five minutes that is ~290 empty jobs a day in Admin → Jobs.
if (!$services->get(\IwacSeo\Service\PingQueue::class)->isEnabled() || !$repository->hasDue()) {
    exit(0);
}
$services->get('Omeka\Job\Dispatcher')->dispatch(\IwacSeo\Job\PingSearchEngines::class, null, $services->get('Omeka\Job\DispatchStrategy\Synchronous'));
