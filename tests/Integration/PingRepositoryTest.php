<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use IwacSeo\Service\PingRepository;
use PHPUnit\Framework\TestCase;

final class PingRepositoryTest extends TestCase
{
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
        $queue->finish($old, true);
        self::assertSame(1, $queue->counts()['pending']);
        $queue->finish($queue->claim(), true);
        self::assertSame(0, $queue->counts()['pending']);
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
        $queue->finish($first, true);
        self::assertSame(1, $queue->counts()['pending']);
        $queue->finish($retry, false, $now + 601);
        self::assertSame([], $queue->claim(now: $now + 602));
        self::assertCount(1, $queue->claim(now: $now + 722));
    }
}
