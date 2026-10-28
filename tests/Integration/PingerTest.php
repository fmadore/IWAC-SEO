<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Service\Pinger;
use IwacSeo\Service\PingOutcome;
use Laminas\Http\Client;
use Laminas\Http\Client\Adapter\Test as TestAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The Pinger reports what IndexNow answered, through Laminas' real HTTP client
 * with its test adapter standing in for the network.
 */
final class PingerTest extends TestCase
{
    private const URLS = ['https://islam.zmo.de/s/afrique_ouest/item/1'];

    private TestAdapter $adapter;

    private Pinger $pinger;

    protected function setUp(): void
    {
        $this->adapter = new TestAdapter();
        $client = new Client();
        $client->setAdapter($this->adapter);
        $this->pinger = new Pinger($client);
    }

    private function submit(string $key = 'a1b2c3d4e5f60718'): int
    {
        return $this->pinger->submitIndexNow('islam.zmo.de', $key, 'https://islam.zmo.de/' . $key . '.txt', self::URLS);
    }

    public function testReturnsTheStatusIndexNowAnswered(): void
    {
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->assertSame(202, $this->submit());
        $this->assertSame(PingOutcome::Accepted, PingOutcome::fromStatus(202));

        $this->adapter->setResponse("HTTP/1.1 403 Forbidden\r\n\r\n");
        $this->assertSame(403, $this->submit());

        $this->adapter->setResponse("HTTP/1.1 503 Service Unavailable\r\n\r\n");
        $this->assertSame(503, $this->submit());
    }

    public function testATransportFailureIsNotSent(): void
    {
        $this->adapter->setNextRequestWillFail(true);
        $this->assertSame(Pinger::NOT_SENT, $this->submit());
    }

    /** A key IndexNow could never verify is not sent at all. */
    public function testAMalformedKeyIsNeverSent(): void
    {
        $this->adapter->setResponse("HTTP/1.1 200 OK\r\n\r\n");
        // Armed to fail the next connection: if either malformed submission
        // reached the network it would consume this, and the well-formed one
        // below would succeed.
        $this->adapter->setNextRequestWillFail(true);
        $this->assertSame(Pinger::NOT_SENT, $this->submit('not-hex!'));
        $this->assertSame(Pinger::NOT_SENT, $this->submit('abc'));

        $this->assertSame(Pinger::NOT_SENT, $this->submit(), 'the first real connection fails as armed');
        $this->assertSame(200, $this->submit());
    }
}
