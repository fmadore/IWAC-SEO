<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Controller\CitationController;
use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationKindMap;
use IwacSeo\Service\HttpCache;
use IwacSeo\Service\PageCitationData;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
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
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Api\Response as ApiResponse;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * /cite/{id}/{format} downloads follow the site the reader is on: its page
 * URL, its language for the abstract and for the collection's name.
 */
final class CitationControllerTest extends TestCase
{
    public function testDownloadFromTheEnglishSiteLinksAndReadsInEnglish(): void
    {
        $csl = $this->download(['site' => 'westafrica']);

        self::assertSame('https://islam.zmo.de/s/westafrica/item/42', $csl['URL']);
        self::assertSame('An English summary.', $csl['abstract']);
        self::assertSame('Islam West Africa Collection', $csl['archive']);
    }

    public function testDownloadWithoutASiteFallsBackToTheDefaultFrenchSite(): void
    {
        $csl = $this->download([]);

        self::assertSame('https://islam.zmo.de/s/afrique_ouest/item/42', $csl['URL']);
        self::assertSame('Un résumé en français.', $csl['abstract']);
        self::assertSame("Collection Islam Afrique de l'Ouest", $csl['archive']);
    }

    public function testAnUnknownOrPrivateSiteIsIgnored(): void
    {
        self::assertSame('https://islam.zmo.de/s/afrique_ouest/item/42', $this->download(['site' => 'staging'])['URL']);
    }

    public function testADownloadCarriesCacheValidatorsFromItsBodyAndTheItemsLastChange(): void
    {
        $response = $this->dispatch(['site' => 'westafrica']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(HttpCache::etag($response->getContent()), $this->header($response, 'ETag'));
        self::assertSame('Wed, 01 Oct 2025 08:00:00 GMT', $this->header($response, 'Last-Modified'), 'the modified date, in GMT');
        self::assertSame('max-age=300, public', $this->header($response, 'Cache-Control'));
        self::assertSame('noindex', $this->header($response, 'X-Robots-Tag'));
    }

    public function testACurrentCopyIsAnswered304WithoutTheFile(): void
    {
        $etag = $this->header($this->dispatch(['site' => 'westafrica']), 'ETag');

        $byTag = $this->dispatch(['site' => 'westafrica'], ['If-None-Match' => '"stale", ' . $etag]);
        $byDate = $this->dispatch(['site' => 'westafrica'], ['If-Modified-Since' => 'Wed, 01 Oct 2025 08:00:00 GMT']);

        foreach ([$byTag, $byDate] as $response) {
            self::assertSame(304, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            self::assertSame($etag, $this->header($response, 'ETag'));
            self::assertSame('max-age=300, public', $this->header($response, 'Cache-Control'));
            self::assertNull($this->header($response, 'Content-Disposition'), 'no file, so no attachment');
        }
    }

    public function testAStaleCopyGetsTheFileAndIfNoneMatchOutranksIfModifiedSince(): void
    {
        $older = $this->dispatch(['site' => 'westafrica'], ['If-Modified-Since' => 'Wed, 01 Oct 2025 07:59:59 GMT']);
        $otherTag = $this->dispatch(['site' => 'westafrica'], [
            'If-None-Match' => '"stale"',
            'If-Modified-Since' => 'Thu, 02 Oct 2025 08:00:00 GMT',
        ]);
        // The French download is another file, so its validator is no
        // match for the English one.
        $etag = $this->header($this->dispatch(['site' => 'westafrica']), 'ETag');
        $french = $this->dispatch([], ['If-None-Match' => $etag]);

        self::assertSame(200, $older->getStatusCode());
        self::assertSame(200, $otherTag->getStatusCode(), 'If-None-Match is evaluated alone when present');
        self::assertSame(200, $french->getStatusCode());
        self::assertNotSame('', $french->getContent());
    }

    public function testOnlyAGetIsAnswered304(): void
    {
        $etag = $this->header($this->dispatch(['site' => 'westafrica']), 'ETag');

        self::assertSame(200, $this->dispatch(['site' => 'westafrica'], ['If-None-Match' => $etag], 'POST')->getStatusCode());
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed> the single CSL-JSON item served
     */
    private function download(array $query): array
    {
        $response = $this->dispatch($query);
        self::assertSame(200, $response->getStatusCode());
        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)[0];
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
    private function dispatch(array $query, array $headers = [], string $method = 'GET'): Response
    {
        $config = require dirname(__DIR__, 2) . '/config/instance.config.php';
        $citation = $config['iwac_seo']['citation'];
        $kinds = new CitationKindMap($citation['class_kinds'], 'item');

        $item = $this->item();
        $api = $this->createStub(ApiManager::class);
        $api->method('read')->willReturnCallback(static function (string $resource, $id) use ($item): ApiResponse {
            if ($resource !== 'items' || (int) $id !== 42) {
                throw new \Omeka\Api\Exception\NotFoundException();
            }
            return new ApiResponse($item);
        });

        $sites = ['afrique_ouest' => $this->site(1, 'afrique_ouest'), 'westafrica' => $this->site(2, 'westafrica')];
        $resolver = $this->getMockBuilder(SiteResolver::class)->disableOriginalConstructor()
            ->onlyMethods(['publicSite', 'defaultSite'])->getMock();
        $resolver->method('publicSite')->willReturnCallback(static fn (string $slug) => $sites[$slug] ?? null);
        $resolver->method('defaultSite')->willReturn($sites['afrique_ouest']);

        $siteSettings = $this->getMockBuilder(SiteSettings::class)->disableOriginalConstructor()->getMock();
        $siteSettings->method('get')->willReturnCallback(
            static fn (string $id, $default = null, $siteId = null) => $id === 'locale' ? [1 => 'fr', 2 => 'en_US'][$siteId] ?? $default : $default
        );
        $settings = $this->getMockBuilder(Settings::class)->disableOriginalConstructor()->getMock();
        $settings->method('get')->willReturnCallback(static fn (string $id, $default = null) => $default);

        $controller = new CitationController(
            new CitationData($kinds, $citation['archive_names']),
            new CitationExport(),
            $api,
            new SettingsGate($settings),
            $resolver,
            $siteSettings,
            new PageCitationData(),
            new PageSeoStore($siteSettings),
        );
        $controller->setPluginManager(new PluginManager(new ServiceManager()));
        $event = new MvcEvent();
        $event->setRouteMatch(new RouteMatch(['action' => 'index', 'id' => '42', 'format' => 'csljson']));
        $controller->setEvent($event);

        $request = new Request();
        $request->setMethod($method);
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
            'dcterms:abstract' => [$this->value('Un résumé en français.', 'fr'), $this->value('An English summary.', 'en')],
        ];
        $class = $this->getMockBuilder(ResourceClassRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['id', 'label'])->getMock();
        $class->method('id')->willReturn(36);
        $class->method('label')->willReturn('Article');

        $item = $this->getMockBuilder(ItemRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['id', 'isPublic', 'value', 'media', 'resourceClass', 'siteUrl', 'modified', 'created'])->getMock();
        $item->method('id')->willReturn(42);
        $item->method('isPublic')->willReturn(true);
        $item->method('value')->willReturnCallback(
            static fn (string $term, array $options = []) => !empty($options['all']) ? ($values[$term] ?? []) : ($values[$term][0] ?? null)
        );
        $item->method('media')->willReturn([]);
        $item->method('resourceClass')->willReturn($class);
        $item->method('modified')->willReturn(new \DateTime('2025-10-01 10:00:00', new \DateTimeZone('Europe/Berlin')));
        $item->method('created')->willReturn(new \DateTime('2020-01-01 00:00:00'));
        $item->method('siteUrl')->willReturnCallback(
            static fn (?string $slug = null, bool $canonical = false): string => 'https://islam.zmo.de/s/' . $slug . '/item/42'
        );
        return $item;
    }

    private function value(string $text, ?string $lang = null): ValueRepresentation
    {
        $value = $this->getMockBuilder(ValueRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['isPublic', 'valueResource', 'value', 'uri', 'lang'])->getMock();
        $value->method('isPublic')->willReturn(true);
        $value->method('valueResource')->willReturn(null);
        $value->method('value')->willReturn($text);
        $value->method('uri')->willReturn(null);
        $value->method('lang')->willReturn($lang);
        return $value;
    }

    private function site(int $id, string $slug): SiteRepresentation
    {
        $site = $this->getMockBuilder(SiteRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['id', 'slug'])->getMock();
        $site->method('id')->willReturn($id);
        $site->method('slug')->willReturn($slug);
        return $site;
    }
}
