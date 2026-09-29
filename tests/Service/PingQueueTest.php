<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Job\PingSearchEngines;
use IwacSeo\Service\PingQueue;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Test\Double\InMemoryPingOutbox;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow dispatch policy — configured-ness, throttle window, batch
 * size — over an in-memory outbox. The durable outbox itself (dedupe, leases,
 * retries) is exercised against a real database in PingRepositoryTest.
 */
final class PingQueueTest extends TestCase
{
    private Settings $settings;
    private Dispatcher $dispatcher;
    private InMemoryPingOutbox $outbox;
    private PingQueue $queue;

    /** @param array<string,mixed> $values */
    private function build(array $values = []): void
    {
        $this->settings = new Settings($values);
        $this->dispatcher = new Dispatcher();
        $this->outbox = new InMemoryPingOutbox();
        $this->queue = new PingQueue(new SettingsGate($this->settings), $this->dispatcher, $this->outbox);
    }

    protected function setUp(): void
    {
        $this->build([
            'iwac_seo_ping_enabled' => '1',
            'iwac_seo_indexnow_key' => 'deadbeefdeadbeef',
        ]);
    }

    public function testEnabledNeedsBothSwitchAndKey(): void
    {
        $this->assertTrue($this->queue->isEnabled());

        $this->build(['iwac_seo_ping_enabled' => '1']);
        $this->assertFalse($this->queue->isEnabled(), 'no key');

        $this->build(['iwac_seo_indexnow_key' => 'deadbeefdeadbeef']);
        $this->assertFalse($this->queue->isEnabled(), 'switched off');

        // A key of whitespace is not a key.
        $this->build(['iwac_seo_ping_enabled' => '1', 'iwac_seo_indexnow_key' => '   ']);
        $this->assertFalse($this->queue->isEnabled());
    }

    public function testPushGoesToTheOutboxNotToSettings(): void
    {
        $this->queue->push('https://example.org/item/1');

        $this->assertSame(['https://example.org/item/1'], $this->outbox->pushed);
        $this->assertNull($this->settings->get('iwac_seo_ping_pending'));
    }

    public function testClaimLeasesOneCappedBatchAndFinishPassesThrough(): void
    {
        $this->outbox->rows = array_map(static fn (int $i): array => ['url' => 'u' . $i], range(1, PingQueue::CAP + 5));

        $batch = $this->queue->claim();
        $this->assertSame(PingQueue::CAP, $this->outbox->lastLimit);
        $this->assertCount(PingQueue::CAP, $batch);

        $this->queue->finish($batch, false);
        $this->assertSame([['rows' => $batch, 'success' => false]], $this->outbox->finished);
    }

    public function testDispatchIsThrottledAndStamped(): void
    {
        $this->queue->dispatchIfDue();
        $this->assertSame([PingSearchEngines::class], $this->dispatcher->dispatched);
        $this->assertNotNull($this->settings->get('iwac_seo_ping_last'));

        // Immediately again: inside the window, so no second job.
        $this->queue->dispatchIfDue();
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    public function testWindowReopensAfterTheInterval(): void
    {
        $this->settings->set('iwac_seo_ping_last', time() - 901);
        $this->queue->dispatchIfDue();
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    /** A failed dispatch must not burn the window, and must not escape. */
    public function testFailedDispatchDoesNotStampTheWindow(): void
    {
        $this->dispatcher->failing = true;
        $this->queue->dispatchIfDue();

        $this->assertSame([], $this->dispatcher->dispatched);
        $this->assertNull($this->settings->get('iwac_seo_ping_last'));
    }
}
