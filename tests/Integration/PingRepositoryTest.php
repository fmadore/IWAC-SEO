<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use IwacSeo\Service\PingOutcome;
use IwacSeo\Service\PingRepository;
use PHPUnit\Framework\TestCase;

final class PingRepositoryTest extends TestCase
{
    /**
     * A refusal (bad key, foreign host) would fail the same way again, so
     * the rows are not resent: they count as failed at once, survive in the
     * table, and come back only through retryFailed().
     */
    public function testRefusedRowsAreFailedAndReturnThroughRetryFailed(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $queue->push('https://example.test/item/2');
        $now = time() + 1;
        $claimed = $queue->claim(now: $now);
        self::assertCount(2, $claimed);

        $queue->finish($claimed, PingOutcome::Refused, $now);
        self::assertSame(['pending' => 0, 'failed' => 2], $queue->counts());
        self::assertFalse($queue->hasDue($now + 86400 * 2), 'a refused URL is never due again by itself');
        self::assertSame([], $queue->claim(now: $now + 86400 * 2));

        $queue->retryFailed();
        self::assertSame(['pending' => 2, 'failed' => 0], $queue->counts());
        $again = $queue->claim(now: $now);
        self::assertCount(2, $again);
        self::assertSame([0, 0], array_map('intval', array_column($again, 'attempts')), 'a fresh start, not one attempt left');
    }

    /** Retryable failures keep backing off until they too run out of attempts. */
    public function testRetriesExhaustAtTheCap(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $now = time() + 1;
        for ($attempt = 1; $attempt <= PingRepository::MAX_ATTEMPTS; $attempt++) {
            $claimed = $queue->claim(now: $now);
            self::assertCount(1, $claimed, 'attempt ' . $attempt);
            $queue->finish($claimed, PingOutcome::Retry, $now);
            $now += 86400;
        }
        self::assertSame([], $queue->claim(now: $now));
        self::assertSame(['pending' => 0, 'failed' => 1], $queue->counts());
    }

    /** A refused batch must not swallow a newer edit made while it was in flight. */
    public function testRefusalLeavesANewerEditPending(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $claimed = $queue->claim();
        $queue->push('https://example.test/item/1');
        $queue->finish($claimed, PingOutcome::Refused);
        self::assertSame(['pending' => 1, 'failed' => 0], $queue->counts());
    }

    public function testConcurrentEditSurvivesAcknowledgement(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $old = $queue->claim();
        self::assertCount(1, $old);
        self::assertSame([], $queue->claim());
        $queue->push('https://example.test/item/1');
        $queue->finish($old, PingOutcome::Accepted);
        self::assertSame(1, $queue->counts()['pending']);
        $queue->finish($queue->claim(), PingOutcome::Accepted);
        self::assertSame(0, $queue->counts()['pending']);
    }

    public function testHasDueIgnoresLeasedAndBackingOffUrls(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        self::assertFalse($queue->hasDue());
        $queue->push('https://example.test/item/1');
        $now = time() + 1;
        self::assertTrue($queue->hasDue($now));
        $claimed = $queue->claim(now: $now);
        self::assertFalse($queue->hasDue($now), 'a leased URL is not due');
        $queue->finish($claimed, PingOutcome::Retry, $now);
        self::assertFalse($queue->hasDue($now + 1), 'a failed URL waits out its backoff');
        self::assertTrue($queue->hasDue($now + 121));
    }

    public function testFailureRetriesAndAbandonedLeaseExpires(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $queue = new PingRepository($connection);
        $queue->install();
        $queue->push('https://example.test/item/1');
        $now = time() + 1;
        $first = $queue->claim(now: $now);
        self::assertCount(1, $first);
        self::assertSame([], $queue->claim(now: $now + 599));
        $retry = $queue->claim(now: $now + 601);
        self::assertCount(1, $retry);
        $queue->finish($first, PingOutcome::Accepted);
        self::assertSame(1, $queue->counts()['pending']);
        $queue->finish($retry, PingOutcome::Retry, $now + 601);
        self::assertSame([], $queue->claim(now: $now + 602));
        self::assertCount(1, $queue->claim(now: $now + 722));
    }
}
