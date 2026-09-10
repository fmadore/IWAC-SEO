<?php
declare(strict_types=1);

namespace IwacSeo\Job;

use IwacSeo\Service\Pinger;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\PingQueue;
use Omeka\Job\AbstractJob;

/**
 * Drains the pending-URL queue (filled by Module::handleContentChange when
 * public items/pages change) and submits it to IndexNow. Runs asynchronously so
 * the saving request is never blocked by the network call.
 *
 * Leases survive worker failures. Only accepted batches are acknowledged.
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
        if ($key === '') {
            $logger->warn('IwacSeo: IndexNow ping enabled but no key is configured.');
            return;
        }

        $batch = $queue->claim();
        $urls = array_column($batch, 'url');
        if ($urls === []) {
            return;
        }
        $groups = [];
        foreach ($batch as $row) {
            $origin = \IwacSeo\Service\SiteResolver::hostFromUrl($row['url']);
            $groups[$origin][] = $row;
        }
        foreach ($groups as $origin => $rows) {
            $host = (string) parse_url($origin, PHP_URL_HOST);
            try {
                $ok = $services->get(Pinger::class)->submitIndexNow(
                    $host,
                    $key,
                    $origin . '/' . $key . '.txt',
                    array_column($rows, 'url')
                );
            } catch (\Throwable $error) {
                $ok = false;
                $logger->err('IwacSeo: IndexNow transport failed: ' . $error->getMessage());
            }
            $queue->finish($rows, $ok);
            $logger->info(sprintf('IwacSeo: IndexNow ping %s for %d URL(s).', $ok ? 'accepted' : 'failed', count($rows)));
        }
    }
}
