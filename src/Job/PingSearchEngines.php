<?php
declare(strict_types=1);

namespace IwacSeo\Job;

use IwacSeo\Service\IndexNowKey;
use IwacSeo\Service\Pinger;
use IwacSeo\Service\PingOutcome;
use IwacSeo\Service\PingQueue;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
use Omeka\Job\AbstractJob;

/**
 * Drains the pending-URL queue (filled by Module::handleContentChange when
 * public items/pages change) and submits it to IndexNow. Runs asynchronously so
 * the saving request is never blocked by the network call.
 *
 * Leases survive worker failures. Each origin's batch is settled by what
 * IndexNow answered ({@see PingOutcome}): accepted rows are acknowledged,
 * transient failures retried with backoff, and refusals parked as failed so
 * they are not resent until an admin has fixed the cause.
 */
class PingSearchEngines extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $queue = $services->get(PingQueue::class);

        if (!$services->get(SettingsGate::class)->isOn('iwac_seo_ping_enabled')) {
            return;
        }
        $key = $queue->key();
        if (!IndexNowKey::isValid($key)) {
            // Nothing is claimed, so the queued URLs keep their attempts and
            // go out once a valid key is saved.
            $logger->warn('IwacSeo: IndexNow ping enabled but no valid key is configured (8–128 hexadecimal characters).');
            return;
        }

        $batch = $queue->claim();
        if ($batch === []) {
            return;
        }
        $groups = [];
        foreach ($batch as $row) {
            $groups[SiteResolver::hostFromUrl($row['url'])][] = $row;
        }
        foreach ($groups as $origin => $rows) {
            $host = (string) parse_url($origin, PHP_URL_HOST);
            $keyLocation = $origin . '/' . $key . '.txt';
            $context = ['count' => count($rows), 'host' => $host];
            try {
                $status = $services->get(Pinger::class)->submitIndexNow(
                    $host,
                    $key,
                    $keyLocation,
                    array_column($rows, 'url')
                );
            } catch (\Throwable $error) {
                $status = Pinger::NOT_SENT;
                $logger->err(
                    'IwacSeo: IndexNow transport failed for {host}: {error}',
                    $context + ['exception' => $error, 'error' => $error->getMessage()]
                );
            }
            $outcome = PingOutcome::fromStatus($status);
            $queue->finish($rows, $outcome);
            $context['status'] = $status;
            match ($outcome) {
                PingOutcome::Accepted => $logger->info(
                    'IwacSeo: IndexNow accepted {count} URL(s) for {host} (HTTP {status}).',
                    $context
                ),
                PingOutcome::Retry => $logger->info(
                    'IwacSeo: IndexNow submission of {count} URL(s) for {host} failed (HTTP {status}, 0 = no response); they will be retried with backoff.',
                    $context
                ),
                PingOutcome::Refused => $logger->warn(
                    'IwacSeo: IndexNow refused {count} URL(s) for {host} with HTTP {status}. They will not be resent: check that the key is valid and served at {keyLocation}, and that the URLs belong to {host}; then re-queue them with scripts/drain-indexnow.php --retry-failed.',
                    $context + ['keyLocation' => $keyLocation]
                ),
            };
        }
    }
}
