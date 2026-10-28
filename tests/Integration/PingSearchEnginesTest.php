<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use IwacSeo\Job\PingSearchEngines;
use IwacSeo\Service\Pinger;
use IwacSeo\Service\PingQueue;
use IwacSeo\Service\PingRepository;
use IwacSeo\Service\SettingsGate;
use Laminas\Http\Client;
use Laminas\Http\Client\Adapter\Test as TestAdapter;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock as MockWriter;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Entity\Job;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The drain job end to end over a real outbox table: what IndexNow answers
 * decides whether the batch is acknowledged, retried or parked, and what the
 * log tells the admin.
 */
final class PingSearchEnginesTest extends TestCase
{
    private PingRepository $outbox;
    private TestAdapter $adapter;
    private MockWriter $log;

    /** @var array<string,mixed> */
    private array $settings = [
        'iwac_seo_ping_enabled' => '1',
        'iwac_seo_indexnow_key' => 'a1b2c3d4e5f60718',
    ];

    private function drain(): void
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            fn ($key, $default = null) => $this->settings[$key] ?? $default
        );
        $gate = new SettingsGate($settings);

        $client = new Client();
        $client->setAdapter($this->adapter);

        $logger = new Logger();
        $logger->addWriter($this->log);
        $logger->addProcessor(new \Omeka\Log\Processor\PsrPlaceholder());

        $services = new ServiceManager();
        $services->setService(SettingsGate::class, $gate);
        $services->setService(PingQueue::class, new PingQueue($gate, $this->createStub(Dispatcher::class), $this->outbox));
        $services->setService(Pinger::class, new Pinger($client));
        $services->setService('Omeka\Logger', $logger);

        (new PingSearchEngines(new Job(), $services))->perform();
    }

    protected function setUp(): void
    {
        $this->outbox = new PingRepository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->outbox->install();
        $this->outbox->push('https://islam.zmo.de/s/afrique_ouest/item/1');
        $this->outbox->push('https://islam.zmo.de/s/westafrica/item/1');
        $this->adapter = new TestAdapter();
        $this->log = new MockWriter();
    }

    /** @return string[] */
    private function messages(string $priority): array
    {
        $out = [];
        foreach ($this->log->events as $event) {
            if ($event['priorityName'] === $priority) {
                $out[] = $event['message'];
            }
        }
        return $out;
    }

    public function testAnAcceptedBatchIsAcknowledged(): void
    {
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 0, 'failed' => 0], $this->outbox->counts());
        self::assertSame(['IwacSeo: IndexNow accepted 2 URL(s) for islam.zmo.de (HTTP 202).'], $this->messages('INFO'));
    }

    public function testARefusedBatchIsParkedAsFailedWithAWarningNamingTheStatus(): void
    {
        $this->adapter->setResponse("HTTP/1.1 403 Forbidden\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 0, 'failed' => 2], $this->outbox->counts());
        $warnings = $this->messages('WARN');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('refused 2 URL(s) for islam.zmo.de with HTTP 403', $warnings[0]);
        self::assertStringContainsString('https://islam.zmo.de/a1b2c3d4e5f60718.txt', $warnings[0]);
        self::assertStringContainsString('--retry-failed', $warnings[0]);
        // The figures travel as context too, for a log writer that keeps it.
        $extra = $this->log->events[0]['extra'];
        self::assertSame([2, 'islam.zmo.de', 403], [$extra['count'], $extra['host'], $extra['status']]);
    }

    public function testAServerErrorIsRetriedNotParked(): void
    {
        $this->adapter->setResponse("HTTP/1.1 503 Service Unavailable\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 2, 'failed' => 0], $this->outbox->counts());
        self::assertFalse($this->outbox->hasDue(), 'backing off');
        self::assertSame([], $this->messages('WARN'));
    }

    /** Nothing is claimed, so the URLs go out untouched once a valid key is saved. */
    public function testAMalformedKeyLeavesTheQueueAlone(): void
    {
        $this->settings['iwac_seo_indexnow_key'] = 'not-a-key';
        $this->adapter->setResponse("HTTP/1.1 202 Accepted\r\n\r\n");
        $this->drain();

        self::assertSame(['pending' => 2, 'failed' => 0], $this->outbox->counts());
        self::assertTrue($this->outbox->hasDue());
        self::assertCount(1, $this->messages('WARN'));
    }
}
