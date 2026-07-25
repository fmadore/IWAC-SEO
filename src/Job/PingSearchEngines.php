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
 * If the queue is at the flood cap the change almost certainly came from a bulk
 * sync, so the ping is skipped — those URLs are discovered through the sitemap
 * instead, and IndexNow is reserved for genuine incremental edits. The cap is
 * PingQueue's, so the queue and its drain can no longer disagree about it.
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

        $urls = $queue->drain();
        if ($urls === []) {
            return;
        }
        if ($queue->isBulk($urls)) {
            $logger->info(sprintf(
                'IwacSeo: skipped IndexNow ping for a bulk change (%d URLs); the sitemap covers discovery.',
                count($urls)
            ));
            return;
        }

        $host = (string) (parse_url($urls[0], PHP_URL_HOST) ?: '');
        $scheme = (string) (parse_url($urls[0], PHP_URL_SCHEME) ?: 'https');
        if ($host === '') {
            return;
        }
        $keyLocation = $scheme . '://' . $host . '/' . $key . '.txt';

        $ok = $services->get(Pinger::class)->submitIndexNow($host, $key, $keyLocation, $urls);
        $logger->info(sprintf(
            'IwacSeo: IndexNow ping %s for %d URL(s).',
            $ok ? 'accepted' : 'failed',
            count($urls)
        ));
    }
}
