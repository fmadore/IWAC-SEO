<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Controller\UnapiController;
use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationKindMap;
use IwacSeo\Service\HttpCache;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\ZoteroRdf;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\Parameters;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ResourceClassRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Api\Response as ApiResponse;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * /unapi serves Zotero RDF and its format lists with cache validators, so the
 * Connector's repeated requests for one page revalidate rather than rebuild.
 */
final class UnapiControllerTest extends TestCase
{
    private const PAGE = 'https://islam.zmo.de/s/westafrica/item/42';

    public function testTheRdfCarriesAnETagAndTheItemsLastChange(): void
    {
        $response = $this->dispatch(['id' => self::PAGE, 'format' => 'rdf_zotero']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<z:itemType>newspaperArticle</z:itemType>', $response->getContent());
        self::assertSame(HttpCache::etag($response->getContent()), $this->header($response, 'ETag'));
        self::assertSame('Wed, 01 Oct 2025 08:00:00 GMT', $this->header($response, 'Last-Modified'));
        self::assertSame('max-age=300, public', $this->header($response, 'Cache-Control'));
    }

    public function testACurrentRdfIsAnswered304(): void
    {
        $query = ['id' => self::PAGE, 'format' => 'rdf_zotero'];
        $etag = (string) $this->header($this->dispatch($query), 'ETag');

        $current = $this->dispatch($query, ['If-None-Match' => $etag]);
        $stale = $this->dispatch($query, ['If-None-Match' => '"stale"']);

        self::assertSame(304, $current->getStatusCode());
        self::assertSame('', $current->getContent());
        self::assertSame(200, $stale->getStatusCode());
    }

    public function testTheFormatListsAreCacheableButDateless(): void
    {
        $server = $this->dispatch([]);
        $forItem = $this->dispatch(['id' => self::PAGE]);

        self::assertSame(200, $server->getStatusCode());
        self::assertSame(300, $forItem->getStatusCode(), 'unAPI answers an id without a format with 300');
        foreach ([$server, $forItem] as $response) {
            self::assertStringContainsString('<format name="rdf_zotero"', $response->getContent());
            self::assertSame(HttpCache::etag($response->getContent()), $this->header($response, 'ETag'));
            self::assertNull($this->header($response, 'Last-Modified'), 'a static list has no record to date it by');
        }
        self::assertSame(304, $this->dispatch([], ['If-None-Match' => (string) $this->header($server, 'ETag')])->getStatusCode());
    }

    private function header(Response $response, string $name): ?string
    {
        $header = $response->getHeaders()->get($name);
        return $header ? $header->getFieldValue() : null;
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers request headers
     */
    private function dispatch(array $query, array $headers = []): Response
    {
        $config = require dirname(__DIR__, 2) . '/config/instance.config.php';
        $citation = $config['iwac_seo']['citation'];
        $kinds = new CitationKindMap($citation['class_kinds'], 'item');

        $api = $this->createStub(ApiManager::class);
        $api->method('read')->willReturn(new ApiResponse($this->item()));
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn (string $id, $default = null) => $default);

        $controller = new UnapiController(
            new ZoteroRdf($kinds, new CitationData($kinds, $citation['archive_names'])),
            $api,
            new SettingsGate($settings),
        );
        $controller->setPluginManager(new PluginManager(new ServiceManager()));
        $event = new MvcEvent();
        $event->setRouteMatch(new RouteMatch(['action' => 'index']));
        $controller->setEvent($event);

        $request = new Request();
        $request->setUri('https://islam.zmo.de/unapi');
        $request->setQuery(new Parameters($query));
        foreach ($headers as $name => $value) {
            $request->getHeaders()->addHeaderLine($name, $value);
        }
        $response = $controller->dispatch($request, new Response());
        self::assertInstanceOf(Response::class, $response);
        return $response;
    }

    private function item(): ItemRepresentation
    {
        $values = [
            'dcterms:title' => [$this->value('Le Hadj 2018')],
            'dcterms:identifier' => [$this->value('iwac-article-0000042')],
            'dcterms:date' => [$this->value('2018-08-21')],
        ];
        $class = $this->createStub(ResourceClassRepresentation::class);
        $class->method('id')->willReturn(36);
        $class->method('label')->willReturn('Article');

        $item = $this->createStub(ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $item->method('isPublic')->willReturn(true);
        $item->method('value')->willReturnCallback(
            static fn (string $term, array $options = []) => !empty($options['all']) ? ($values[$term] ?? []) : ($values[$term][0] ?? null)
        );
        $item->method('media')->willReturn([]);
        $item->method('resourceClass')->willReturn($class);
        $item->method('displayTitle')->willReturn('Le Hadj 2018');
        $item->method('modified')->willReturn(new \DateTime('2025-10-01 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $item->method('siteUrl')->willReturnCallback(
            static fn (?string $slug = null, bool $canonical = false): string => 'https://islam.zmo.de/s/' . $slug . '/item/42'
        );
        return $item;
    }

    private function value(string $text): ValueRepresentation
    {
        $value = $this->createStub(ValueRepresentation::class);
        $value->method('isPublic')->willReturn(true);
        $value->method('valueResource')->willReturn(null);
        $value->method('value')->willReturn($text);
        $value->method('uri')->willReturn(null);
        $value->method('lang')->willReturn(null);
        $value->method('__toString')->willReturn($text);
        return $value;
    }
}
