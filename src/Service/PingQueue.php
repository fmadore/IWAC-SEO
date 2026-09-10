<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Job\PingSearchEngines;
use Omeka\Job\Dispatcher;

/**
 * Dispatch policy over the durable outbox injected by PingQueueFactory.
 * The optional settings-backed adapter and drain/isBulk methods are retained
 * for constructor compatibility; production jobs use claim/finish exclusively.
 */
class PingQueue
{
    /**
     * Maximum leased batch size; also the legacy settings adapter's flood cap.
     */
    public const CAP = 200;

    /** How often (seconds) a drain job may be dispatched. */
    private const DISPATCH_INTERVAL = 900;

    private const PENDING = 'iwac_seo_ping_pending';
    private const LAST_DISPATCH = 'iwac_seo_ping_last';

    public function __construct(
        private readonly SettingsGate $settings,
        private readonly Dispatcher $dispatcher,
        private readonly ?PingRepository $repository = null,
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

    /** Queue a URL for submission, de-duplicated and capped. */
    public function push(string $url): void
    {
        if ($this->repository !== null) {
            $this->repository->push($url);
            return;
        }
        if ($url === '') {
            return;
        }
        $pending = $this->settings->list(self::PENDING);
        if (count($pending) >= self::CAP || in_array($url, $pending, true)) {
            return;
        }
        $pending[] = $url;
        $this->settings->set(self::PENDING, $pending);
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
     * Claim the queue: return its de-duplicated contents and empty it in the
     * same breath, so a second job cannot submit the same batch.
     *
     * @return string[]
     */
    public function drain(): array
    {
        $pending = $this->settings->list(self::PENDING);
        $this->settings->set(self::PENDING, []);
        return array_values(array_unique(array_filter($pending)));
    }

    /** @return array<int,array<string,mixed>> */
    public function claim(): array
    {
        return $this->repository?->claim(self::CAP) ?? [];
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function finish(array $rows, bool $success): void
    {
        $this->repository?->finish($rows, $success);
    }

    /**
     * Whether a drained batch is a bulk change rather than editorial work.
     * Such batches are skipped: the sitemap covers their discovery, and
     * IndexNow is reserved for genuine incremental edits.
     *
     * @param string[] $urls
     */
    public function isBulk(array $urls): bool
    {
        return count($urls) >= self::CAP;
    }
}
