<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Job\PingSearchEngines;
use Omeka\Job\Dispatcher;

/**
 * The IndexNow submission queue: which URLs are pending, when a job may be
 * dispatched to drain them, and when a batch is large enough to be a bulk sync
 * rather than an editorial change.
 *
 * This was ~65 lines inside {@see \IwacSeo\Module::handleContentChange()} —
 * business logic in the bootstrap class — and the flood cap had to be a
 * `public const` on Module purely so the job could agree with it. Both halves
 * of that agreement now live here, and the whole policy is testable without an
 * Omeka event.
 *
 * The queue is a plain global setting (a list of URLs), so it survives across
 * requests and needs no table. It is deliberately capped: a bulk import would
 * otherwise grow it without bound, and IndexNow is meant for incremental edits
 * — bulk content is discovered through the sitemap instead.
 */
class PingQueue
{
    /**
     * Pending-URL cap. A queue that reaches it is treated as a bulk sync and
     * skipped at drain time rather than submitted.
     */
    public const CAP = 200;

    /** How often (seconds) a drain job may be dispatched. */
    private const DISPATCH_INTERVAL = 900;

    private const PENDING = 'iwac_seo_ping_pending';
    private const LAST_DISPATCH = 'iwac_seo_ping_last';

    public function __construct(
        private readonly SettingsGate $settings,
        private readonly Dispatcher $dispatcher,
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
