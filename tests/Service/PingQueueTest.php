<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Job\PingSearchEngines;
use IwacSeo\Service\PingQueue;
use IwacSeo\Service\SettingsGate;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow queue policy — dedupe, flood cap, throttle window. This logic
 * used to live inside Module::handleContentChange() and could not be reached
 * without firing an Omeka API event.
 */
final class PingQueueTest extends TestCase
{
    private Settings $settings;
    private Dispatcher $dispatcher;
    private PingQueue $queue;

    /** @param array<string,mixed> $values */
    private function build(array $values = []): void
    {
        $this->settings = new Settings($values);
        $this->dispatcher = new Dispatcher();
        $this->queue = new PingQueue(new SettingsGate($this->settings), $this->dispatcher);
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

    public function testPushDeduplicates(): void
    {
        $this->queue->push('https://example.org/item/1');
        $this->queue->push('https://example.org/item/2');
        $this->queue->push('https://example.org/item/1');

        $this->assertSame(
            ['https://example.org/item/1', 'https://example.org/item/2'],
            $this->settings->get('iwac_seo_ping_pending')
        );
    }

    public function testPushIgnoresEmptyUrls(): void
    {
        $this->queue->push('');
        $this->assertNull($this->settings->get('iwac_seo_ping_pending'));
    }

    public function testQueueStopsGrowingAtTheFloodCap(): void
    {
        for ($i = 0; $i < PingQueue::CAP + 25; $i++) {
            $this->queue->push('https://example.org/item/' . $i);
        }
        $this->assertCount(PingQueue::CAP, $this->settings->get('iwac_seo_ping_pending'));
    }

    public function testDrainClaimsAndEmpties(): void
    {
        $this->queue->push('https://example.org/item/1');
        $this->queue->push('https://example.org/item/2');

        $this->assertSame(
            ['https://example.org/item/1', 'https://example.org/item/2'],
            $this->queue->drain()
        );
        // A second job must not resubmit the same batch.
        $this->assertSame([], $this->queue->drain());
    }

    public function testBulkBatchesAreRecognised(): void
    {
        $this->assertFalse($this->queue->isBulk(['a', 'b']));
        $this->assertTrue($this->queue->isBulk(array_fill(0, PingQueue::CAP, 'u')));
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
