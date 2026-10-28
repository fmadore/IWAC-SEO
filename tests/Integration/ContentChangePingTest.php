<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Service\PingQueue;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Test\Double\InMemoryPingOutbox;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\SitePageAdapter;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Request;
use Omeka\Api\Response;
use Omeka\Entity\Item;
use Omeka\Entity\SitePage;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The IndexNow listeners, driven through Omeka's real event objects in the
 * order its API manager fires them. The api.*.post events carry the Doctrine
 * entity, not its representation (Manager::finalize() converts the response
 * only after they run), and an entity has no siteUrl(): read as it arrives, no
 * save ever reached the outbox.
 */
final class ContentChangePingTest extends TestCase
{
    private const ITEM_ADAPTER = 'Omeka\Api\Adapter\ItemAdapter';
    private const PAGE_ADAPTER = 'Omeka\Api\Adapter\SitePageAdapter';

    private InMemoryPingOutbox $outbox;

    /** @var array<string,mixed> */
    private array $settings;

    private SharedEventManager $sharedEvents;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';

        $this->outbox = new InMemoryPingOutbox();
        $this->settings = [
            'iwac_seo_ping_enabled' => '1',
            'iwac_seo_indexnow_key' => 'a1b2c3d4e5f60718',
            'iwac_seo_sitemap_enabled' => '0',
        ];
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            fn ($key, $default = null) => $this->settings[$key] ?? $default
        );
        $gate = new SettingsGate($settings);

        $siteResolver = $this->createStub(SiteResolver::class);
        $siteResolver->method('defaultSlug')->willReturn('afrique_ouest');

        $logger = new Logger();
        $logger->addWriter(new Noop());

        $services = new ServiceManager();
        $services->setService(SettingsGate::class, $gate);
        $services->setService(PingQueue::class, new PingQueue($gate, $this->createStub(Dispatcher::class), $this->outbox));
        $services->setService(SitemapGenerator::class, $this->createStub(SitemapGenerator::class));
        $services->setService(SiteResolver::class, $siteResolver);
        $services->setService('Omeka\Logger', $logger);

        $module = new \IwacSeo\Module();
        $module->setServiceLocator($services);
        $this->sharedEvents = new SharedEventManager();
        $module->attachListeners($this->sharedEvents);
    }

    public function testCreatingAPublicItemQueuesItsPageOnTheDefaultSite(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame(['https://islam.zmo.de/s/afrique_ouest/item/42'], $this->outbox->pushed);
    }

    public function testCreatingAPrivateItemQueuesNothing(): void
    {
        $item = $this->item(false);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testAnUpdateThatMakesAnItemPrivateQueuesItSoEnginesDropIt(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);
        $request = $this->request(Request::UPDATE);

        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $adapter, $request, entity: $item);
        $item->setIsPublic(false);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $adapter, $request, $item);

        self::assertSame(['https://islam.zmo.de/s/afrique_ouest/item/42'], $this->outbox->pushed);
    }

    public function testAnUpdateToAnItemThatStaysPrivateQueuesNothing(): void
    {
        $item = $this->item(false);
        $adapter = $this->itemAdapter($item);
        $request = $this->request(Request::UPDATE);

        $this->fire(self::ITEM_ADAPTER, 'api.hydrate.pre', $adapter, $request, entity: $item);
        $this->fire(self::ITEM_ADAPTER, 'api.update.post', $adapter, $request, $item);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testADeletedItemIsQueuedAtTheUrlItHadBeforeTheDeletion(): void
    {
        $item = $this->item(true);
        $deleted = false;
        $adapter = $this->itemAdapter($item, $deleted);
        $request = $this->request(Request::DELETE);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $request);
        // Doctrine has removed the row and cleared the id: no URL can be built now.
        $deleted = true;
        $this->fire(self::ITEM_ADAPTER, 'api.delete.post', $adapter, $request, $item);

        self::assertSame(['https://islam.zmo.de/s/afrique_ouest/item/42'], $this->outbox->pushed);
    }

    public function testADeletionThatNeverCompletesQueuesNothing(): void
    {
        $item = $this->item(true);
        $adapter = $this->itemAdapter($item);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $this->request(Request::DELETE));

        self::assertSame([], $this->outbox->pushed);
    }

    public function testAPageIsQueuedOnItsOwnSite(): void
    {
        $page = new SitePage();
        $page->setIsPublic(true);
        $adapter = $this->pageAdapter($page, 'westafrica', true);

        $this->fire(self::PAGE_ADAPTER, 'api.update.post', $adapter, $this->request(Request::UPDATE), $page);

        self::assertSame(['https://islam.zmo.de/s/westafrica/page/about'], $this->outbox->pushed);
    }

    public function testAPageOnAPrivateSiteQueuesNothing(): void
    {
        $page = new SitePage();
        $page->setIsPublic(true);
        $adapter = $this->pageAdapter($page, 'staging', false);

        $this->fire(self::PAGE_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $page);

        self::assertSame([], $this->outbox->pushed);
    }

    public function testNothingIsReadOrQueuedWhilePingingIsOff(): void
    {
        $this->settings['iwac_seo_ping_enabled'] = '0';
        $item = $this->item(true);
        $adapter = $this->createMock(ItemAdapter::class);
        $adapter->expects(self::never())->method('findEntity');
        $adapter->expects(self::never())->method('getRepresentation');
        $request = $this->request(Request::DELETE);

        $this->fire(self::ITEM_ADAPTER, 'api.delete.pre', $adapter, $request);
        $this->fire(self::ITEM_ADAPTER, 'api.delete.post', $adapter, $request, $item);
        $this->fire(self::ITEM_ADAPTER, 'api.create.post', $adapter, $this->request(Request::CREATE), $item);

        self::assertSame([], $this->outbox->pushed);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** Trigger $name as Omeka's API does: the adapter as target, the request and response as params. */
    private function fire(
        string $identifier,
        string $name,
        object $adapter,
        Request $request,
        ?object $content = null,
        ?object $entity = null
    ): void {
        $params = ['request' => $request];
        if ($content !== null) {
            $params['response'] = new Response($content);
        }
        if ($entity !== null) {
            $params['entity'] = $entity;
        }
        (new EventManager($this->sharedEvents, [$identifier]))->triggerEvent(new Event($name, $adapter, $params));
    }

    private function request(string $operation): Request
    {
        $request = new Request($operation, 'items');
        if ($operation === Request::DELETE || $operation === Request::UPDATE) {
            $request->setId(42);
        }
        return $request;
    }

    private function item(bool $public): Item
    {
        $item = new Item();
        $item->setIsPublic($public);
        return $item;
    }

    /**
     * An item adapter whose representation builds the record's canonical URL
     * on whatever site slug it is asked for — until $deleted turns true.
     */
    private function itemAdapter(Item $item, bool &$deleted = false): ItemAdapter
    {
        $representation = $this->createStub(ItemRepresentation::class);
        $representation->method('siteUrl')->willReturnCallback(
            static function ($slug = null, $canonical = false) use (&$deleted): string {
                if ($deleted) {
                    throw new \RuntimeException('The deleted entity has no id to route.');
                }
                return ($canonical ? 'https://islam.zmo.de' : '') . '/s/' . $slug . '/item/42';
            }
        );
        $adapter = $this->createStub(ItemAdapter::class);
        $adapter->method('findEntity')->willReturn($item);
        $adapter->method('getRepresentation')->willReturn($representation);
        return $adapter;
    }

    private function pageAdapter(SitePage $page, string $siteSlug, bool $sitePublic): SitePageAdapter
    {
        $site = $this->createStub(SiteRepresentation::class);
        $site->method('slug')->willReturn($siteSlug);
        $site->method('isPublic')->willReturn($sitePublic);
        $representation = $this->createStub(SitePageRepresentation::class);
        $representation->method('site')->willReturn($site);
        $representation->method('siteUrl')->willReturnCallback(
            static fn ($slug = null, $canonical = false): string
                => ($canonical ? 'https://islam.zmo.de' : '') . '/s/' . $slug . '/page/about'
        );
        $adapter = $this->createStub(SitePageAdapter::class);
        $adapter->method('findEntity')->willReturn($page);
        $adapter->method('getRepresentation')->willReturn($representation);
        return $adapter;
    }
}
