<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Controller\CitationController;
use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\CitationKindMap;
use IwacSeo\Service\PageCitationData;
use IwacSeo\Service\PageCitationDataFactory;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Service\ZoteroRdf;
use IwacSeo\View\Helper\Citation as CitationHelper;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\PluginManager;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Helper\ServerUrl;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Response as ApiResponse;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * /cite/page/{id}/{format} — an exhibit page cites itself: its own site's URL
 * and language, the editor's citation fields from the static-page SEO table,
 * and the configured defaults where those are blank. Only a public page that
 * carries the "How to cite this page" block is served.
 */
final class PageCitationControllerTest extends TestCase
{
    /** @var array<int,array<int,array<string,mixed>>> site id => the iwac_seo_pages map */
    private array $seoTables = [];

    public function testAnEnglishPageWithNoOverridesTakesTheDefaults(): void
    {
        $response = $this->download(10, 'csljson');
        $csl = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)[0];

        self::assertSame('webpage', $csl['type']);
        self::assertSame('Hajj in Burkina Faso', $csl['title']);
        self::assertSame([['family' => 'Madore', 'given' => 'Frédérick']], $csl['author']);
        self::assertSame([[2022, 10, 6]], $csl['issued']['date-parts']); // the page's creation day
        self::assertSame('Islam West Africa Collection', $csl['container-title']);
        self::assertSame('Leibniz-Zentrum Moderner Orient', $csl['publisher']);
        self::assertSame('https://islam.zmo.de/s/westafrica/page/hajj-bf', $csl['URL']);
        self::assertSame('en', $csl['language']);
        self::assertStringContainsString('filename="hajj-bf.json"', (string) $response->getHeaders()->get('Content-Disposition')?->getFieldValue());
    }

    public function testTheFrenchPageReadsTheEditorsFieldsInFrench(): void
    {
        $this->seoTables[1] = [11 => [
            'citation_title'   => 'Le hadj au Burkina Faso',
            'citation_authors' => "Frédérick Madore\n{Leibniz-Zentrum Moderner Orient}",
            'citation_date'    => '2026-10-23',
            'description'      => 'Une frise chronologique du pèlerinage.',
        ]];
        $csl = json_decode($this->download(11, 'csljson')->getContent(), true, 512, JSON_THROW_ON_ERROR)[0];

        self::assertSame('Le hadj au Burkina Faso', $csl['title']);
        self::assertSame(
            [['family' => 'Madore', 'given' => 'Frédérick'], ['literal' => 'Leibniz-Zentrum Moderner Orient']],
            $csl['author']
        );
        self::assertSame([[2026, 10, 23]], $csl['issued']['date-parts']);
        self::assertSame("Collection Islam Afrique de l'Ouest", $csl['container-title']);
        self::assertSame('https://islam.zmo.de/s/afrique_ouest/page/hadj-bf', $csl['URL']);
        self::assertSame('fr', $csl['language']);
        self::assertSame('Une frise chronologique du pèlerinage.', $csl['abstract']);
    }

    public function testRisAndBibtexAreServedWithTheirMediaTypes(): void
    {
        $ris = $this->download(10, 'ris');
        self::assertStringStartsWith('TY  - ELEC', $ris->getContent());
        self::assertStringStartsWith('application/x-research-info-systems', (string) $ris->getHeaders()->get('Content-Type')?->getFieldValue());
        self::assertStringStartsWith('@online{hajj-bf,', $this->download(10, 'bibtex')->getContent());
    }

    public function testAPageWithoutTheBlockIsNotCitable(): void
    {
        self::assertSame(404, $this->download(12, 'csljson', false)->getStatusCode());
    }

    public function testAPrivatePageAMissingPageAndAnUnknownFormatAre404(): void
    {
        self::assertSame(404, $this->download(13, 'csljson', false)->getStatusCode());
        self::assertSame(404, $this->download(99, 'csljson', false)->getStatusCode());
        self::assertSame(404, $this->download(10, 'docx', false)->getStatusCode());
    }

    /**
     * iwacCitation($page) is what the block renders: the same view-model as an
     * item's, in the page's language, linking the page's own downloads.
     */
    public function testTheViewHelperBuildsThePanelForACitablePageOnly(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $citation = $config['iwac_seo']['citation'];
        $pages = $this->pages();
        $siteSettings = $this->siteSettings();

        $services = new ServiceManager();
        $services->setService('Config', $config);
        $kinds = new CitationKindMap($citation['class_kinds'], 'item');
        $citationData = new CitationData($kinds, $citation['archive_names']);
        $helper = new CitationHelper(
            $citationData,
            new CitationFormatter(),
            new ZoteroRdf($kinds, $citationData),
            $citation['default_style'],
            $citation['styles'],
            $citation['formats'],
            true,
            (new PageCitationDataFactory())($services, PageCitationData::class),
            new PageSeoStore($siteSettings),
        );
        $helpers = new HelperPluginManager(new ServiceManager());
        $helpers->setService('lang', new class extends AbstractHelper {
            public function __invoke(): string
            {
                return 'fr';
            }
        });
        $helpers->setService('serverUrl', (new ServerUrl())->setScheme('https')->setHost('islam.zmo.de'));
        $view = new PhpRenderer();
        $view->setHelperPluginManager($helpers);
        $helper->setView($view);

        $model = $helper($pages[11]);
        self::assertNotNull($model);
        self::assertSame('webpage', $model['record']['kind']);
        self::assertSame('hadj-bf', $model['record']['key']);
        self::assertSame('chicago', $model['defaultStyle']);
        self::assertStringContainsString('6 octobre 2022', $model['styles']['chicago']['html']);
        self::assertStringContainsString('https://islam.zmo.de/s/afrique_ouest/page/hadj-bf', $model['styles']['apa']['html']);
        self::assertSame('https://islam.zmo.de/cite/page/11/bibtex', $model['downloads']['bibtex']['url']);
        self::assertNull($model['zoteroRdf']);

        self::assertNull($helper($pages[12]), 'a page without the block has no panel');
    }

    private function download(int $pageId, string $format, bool $expectOk = true): Response
    {
        $config = require dirname(__DIR__, 2) . '/config/module.config.php';
        $citation = $config['iwac_seo']['citation'];
        $pages = $this->pages();
        $siteSettings = $this->siteSettings();

        $api = $this->createMock(ApiManager::class);
        $api->method('read')->willReturnCallback(static function (string $resource, $id) use ($pages): ApiResponse {
            if ($resource !== 'site_pages' || !isset($pages[$id])) {
                throw new NotFoundException();
            }
            return new ApiResponse($pages[$id]);
        });

        $settings = $this->getMockBuilder(Settings::class)->disableOriginalConstructor()->getMock();
        $settings->method('get')->willReturnCallback(static fn (string $id, $default = null) => $default);
        $resolver = $this->getMockBuilder(SiteResolver::class)->disableOriginalConstructor()->getMock();

        $services = new ServiceManager();
        $services->setService('Config', $config);
        $controller = new CitationController(
            new CitationData(new CitationKindMap($citation['class_kinds'], 'item'), $citation['archive_names']),
            new CitationExport(),
            $api,
            new SettingsGate($settings),
            $resolver,
            $siteSettings,
            (new PageCitationDataFactory())($services, PageCitationData::class),
            new PageSeoStore($siteSettings),
        );
        $controller->setPluginManager(new PluginManager(new ServiceManager()));
        $event = new MvcEvent();
        $event->setRouteMatch(new RouteMatch(['action' => 'page', 'id' => (string) $pageId, 'format' => $format]));
        $controller->setEvent($event);

        $response = $controller->dispatch(new Request(), new Response());
        self::assertInstanceOf(Response::class, $response);
        if ($expectOk) {
            self::assertSame(200, $response->getStatusCode());
        }
        return $response;
    }

    /** @return array<int,SitePageRepresentation> */
    private function pages(): array
    {
        $sites = [1 => $this->site(1, 'afrique_ouest', true), 2 => $this->site(2, 'westafrica', true)];
        return [
            10 => $this->page(10, 'hajj-bf', 'Hajj in Burkina Faso', $sites[2], true, true),
            11 => $this->page(11, 'hadj-bf', 'Hadj au Burkina Faso', $sites[1], true, true),
            12 => $this->page(12, 'about', 'About', $sites[2], true, false),
            13 => $this->page(13, 'draft', 'Draft', $sites[2], false, true),
        ];
    }

    /** Site settings that answer each site's locale and its static-page SEO table. */
    private function siteSettings(): SiteSettings
    {
        $target = null;
        $siteSettings = $this->getMockBuilder(SiteSettings::class)->disableOriginalConstructor()->getMock();
        $siteSettings->method('setTargetId')->willReturnCallback(static function ($id) use (&$target): void {
            $target = $id;
        });
        $siteSettings->method('get')->willReturnCallback(
            function (string $id, $default = null, $siteId = null) use (&$target) {
                return match ($id) {
                    'locale' => [1 => 'fr', 2 => 'en_US'][$siteId] ?? $default,
                    'iwac_seo_pages' => $this->seoTables[$target] ?? $default,
                    default => $default,
                };
            }
        );
        return $siteSettings;
    }

    private function page(int $id, string $slug, string $title, SiteRepresentation $site, bool $public, bool $withBlock): SitePageRepresentation
    {
        $blocks = [$this->block('html')];
        if ($withBlock) {
            $blocks[] = $this->block(PageCitationData::BLOCK_LAYOUT);
        }
        $page = $this->getMockBuilder(SitePageRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['id', 'slug', 'title', 'site', 'isPublic', 'blocks', 'created', 'siteUrl'])->getMock();
        $page->method('id')->willReturn($id);
        $page->method('slug')->willReturn($slug);
        $page->method('title')->willReturn($title);
        $page->method('site')->willReturn($site);
        $page->method('isPublic')->willReturn($public);
        $page->method('blocks')->willReturn($blocks);
        $page->method('created')->willReturn(new \DateTime('2022-10-06 09:30:00'));
        $page->method('siteUrl')->willReturnCallback(
            static fn (?string $siteSlug = null, bool $canonical = false): string => 'https://islam.zmo.de/s/' . $siteSlug . '/page/' . $slug
        );
        return $page;
    }

    private function block(string $layout): SitePageBlockRepresentation
    {
        $block = $this->getMockBuilder(SitePageBlockRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['layout'])->getMock();
        $block->method('layout')->willReturn($layout);
        return $block;
    }

    private function site(int $id, string $slug, bool $public): SiteRepresentation
    {
        $site = $this->getMockBuilder(SiteRepresentation::class)->disableOriginalConstructor()
            ->onlyMethods(['id', 'slug', 'title', 'isPublic'])->getMock();
        $site->method('id')->willReturn($id);
        $site->method('slug')->willReturn($slug);
        $site->method('title')->willReturn($slug === 'westafrica' ? 'Islam West Africa Collection' : "Collection Islam Afrique de l'Ouest");
        $site->method('isPublic')->willReturn($public);
        return $site;
    }
}
