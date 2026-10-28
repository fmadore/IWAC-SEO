<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\IndexNowKey;
use IwacSeo\Service\Pinger;
use IwacSeo\Service\PingOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How an IndexNow answer settles its batch. The statuses are the ones the
 * protocol documents (200/202 accepted; 400, 403, 422 refused; 429 too many
 * requests) plus a transport failure, which the Pinger reports as NOT_SENT.
 */
final class PingOutcomeTest extends TestCase
{
    /** @return array<string,array{int,PingOutcome}> */
    public static function statuses(): array
    {
        return [
            'OK' => [200, PingOutcome::Accepted],
            'Accepted, key validation pending' => [202, PingOutcome::Accepted],
            'no response' => [Pinger::NOT_SENT, PingOutcome::Retry],
            'request timeout' => [408, PingOutcome::Retry],
            'too many requests' => [429, PingOutcome::Retry],
            'server error' => [500, PingOutcome::Retry],
            'service unavailable' => [503, PingOutcome::Retry],
            'bad request' => [400, PingOutcome::Refused],
            'key not valid' => [403, PingOutcome::Refused],
            'URLs not of this host' => [422, PingOutcome::Refused],
            'redirect left unfollowed' => [301, PingOutcome::Refused],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusSettlesTheBatch(int $status, PingOutcome $expected): void
    {
        $this->assertSame($expected, PingOutcome::fromStatus($status));
    }

    public function testKeyFormatIsTheRouteConstraintAnchored(): void
    {
        $this->assertTrue(IndexNowKey::isValid('deadbeef'));
        $this->assertTrue(IndexNowKey::isValid(str_repeat('aB3', 42) . 'cd'), '128 characters');
        $this->assertFalse(IndexNowKey::isValid('deadbee'), 'seven characters');
        $this->assertFalse(IndexNowKey::isValid(str_repeat('a', 129)));
        $this->assertFalse(IndexNowKey::isValid('deadbeefg'), 'not hexadecimal');
        $this->assertFalse(IndexNowKey::isValid("deadbeef\n"), 'a trailing newline is not part of a key');
        $this->assertFalse(IndexNowKey::isValid(''));
        $this->assertSame('/\A[A-Fa-f0-9]{8,128}\z/', IndexNowKey::regex());
    }
}
