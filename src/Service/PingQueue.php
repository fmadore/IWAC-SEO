<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Job\PingSearchEngines;
use Omeka\Job\Dispatcher;

/**
 * IndexNow dispatch policy over the durable outbox: whether pinging is
 * configured, how often a save may start a drain job, and the batch size a
 * job leases. The outbox itself (dedupe, leases, retries) is
 * {@see PingRepository}; the five-minute cron drains it independently of
 * saves (scripts/drain-indexnow.php).
 */
class PingQueue
{
    /** URLs leased per job. */
    public const CAP = 200;

    /** How often (seconds) a save may dispatch a drain job. */
    private const DISPATCH_INTERVAL = 900;

    private const LAST_DISPATCH = 'iwac_seo_ping_last';

    public function __construct(
        private readonly SettingsGate $settings,
        private readonly Dispatcher $dispatcher,
        private readonly PingOutboxInterface $outbox,
    ) {
    }

    /** Pinging is configured: switched on *and* carrying an ownership key. */
    public function isEnabled(): bool
    {
        return $this->settings->isOn('iwac_seo_ping_enabled') && $this->key() !== '';
    }

    /** The IndexNow ownership key, or '' when unset. */
    public function key(): string
    {
        return $this->settings->text('iwac_seo_indexnow_key');
    }

    /** Queue a URL for submission. */
    public function push(string $url): void
    {
        $this->outbox->push($url);
    }

    /**
     * Dispatch a drain job if the throttle window has elapsed. The window is
     * stamped only after a successful dispatch, so a failed one does not burn
     * it; a dispatch failure is swallowed because SEO bookkeeping must never
     * break the save that triggered it.
     */
    public function dispatchIfDue(): void
    {
        $now = time();
        if ($now - $this->settings->int(self::LAST_DISPATCH) < self::DISPATCH_INTERVAL) {
            return;
        }
        try {
            $this->dispatcher->dispatch(PingSearchEngines::class);
            $this->settings->set(self::LAST_DISPATCH, $now);
        } catch (\Throwable $e) {
            // never let SEO bookkeeping break a save
        }
    }

    /**
     * Lease the next batch.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim(): array
    {
        return $this->outbox->claim(self::CAP);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function finish(array $rows, bool $success): void
    {
        $this->outbox->finish($rows, $success);
    }
}
