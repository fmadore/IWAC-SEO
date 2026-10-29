<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationKindMap;
use IwacSeo\Service\CitationMeta;
use IwacSeo\Service\HeadMetadata;
use IwacSeo\Service\HeadWriter;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\Signposting;
use IwacSeo\Service\StructuredData;
use IwacSeo\Service\ZoteroRdf;
use Laminas\EventManager\Event;
use Laminas\Http\Header\MultipleHeaderInterface;
use Laminas\Http\PhpEnvironment\Response;
use Laminas\Mvc\Application;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\Doctype;
use Laminas\View\Helper\HeadLink;
use Laminas\View\Helper\HeadMeta;
use Laminas\View\Helper\HeadScript;
use Laminas\View\Helper\HeadTitle;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ResourceClassRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * FAIR Signposting against Omeka's real representation classes, Laminas's
 * real HeadLink helper and a real Laminas HTTP response: the link set a
 * record page announces, the head links that carry it, and the `Link` header
 * the Module repeats it in.
 */
final class SignpostingTest extends TestCase
{
    private const ORIGIN = 'https://islam.zmo.de';
    private const PAGE = self::ORIGIN . '/s/westafrica/item/42';
    private const RIGHTS = 'http://rightsstatements.org/vocab/InC-EDU/1.0/';

    private CitationKindMap $kinds;

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/instance.config.php';
        $this->kinds = new CitationKindMap($config['iwac_seo']['citation']['class_kinds'], 'item');
    }

    public function testANewspaperArticleIsSignpostedOnTheReadersSite(): void
    {
        $links = $this->signposting()->links($this->article(), self::PAGE, 'westafrica', self::ORIGIN);

        self::assertSame(['rel' => 'cite-as', 'href' => self::PAGE], $links[0], 'cite-as is the page itself');
        self::assertSame([
            [self::ORIGIN . '/cite/42/bibtex?site=westafrica', 'application/x-bibtex'],
            [self::ORIGIN . '/cite/42/ris?site=westafrica', 'application/x-research-info-systems'],
            [self::ORIGIN . '/cite/42/csljson?site=westafrica', 'application/vnd.citationstyles.csl+json'],
            [self::ORIGIN . '/unapi?id=' . rawurlencode(self::PAGE) . '&format=rdf_zotero', 'application/rdf+xml'],
        ], $this->hrefsAndTypes($links, 'describedby'));
        self::assertSame(
            ['https://schema.org/NewsArticle', 'https://schema.org/AboutPage'],
            array_column($this->rel($links, 'type'), 'href')
        );
        self::assertSame([self::RIGHTS], array_column($this->rel($links, 'license'), 'href'));
        self::assertSame([
            [self::ORIGIN . '/files/original/article.pdf', 'application/pdf'],
            [self::ORIGIN . '/files/original/scan.jpg', 'image/jpeg'],
        ], $this->hrefsAndTypes($links, 'item'), 'public files in the work\'s own form, private ones never');
        self::assertSame(
            [self::ORIGIN . '/s/westafrica/item-set/7'],
            array_column($this->rel($links, 'collection'), 'href'),
            'the public item set, on the reader\'s site'
        );
    }

    public function testCiteAsIsNeverTheDOI(): void
    {
        $reference = $this->item(35, ['bibo:doi' => [$this->value('https://doi.org/10.1234/abcd', 'https://doi.org/10.1234/abcd')]]);

        $links = $this->signposting()->links($reference, self::PAGE, 'westafrica', self::ORIGIN);

        self::assertSame(self::PAGE, $links[0]['href']);
        self::assertStringNotContainsString('doi', (string) json_encode($links), 'the DOI resolves to the publisher, not this record');
        // Citable, but not one of the kinds /unapi serves.
        self::assertSame(
            ['application/x-bibtex', 'application/x-research-info-systems', 'application/vnd.citationstyles.csl+json'],
            array_column($this->rel($links, 'describedby'), 'type')
        );
    }

    public function testAuthorsAreLinkedByORCIDElseAnAuthorityURIElseTheirPage(): void
    {
        $book = $this->item(40, [
            'bibo:authorList' => [
                $this->linkedValue($this->person(901, ['https://www.wikidata.org/wiki/Q1', 'https://orcid.org/0000-0003-0959-2092'])),
                $this->linkedValue($this->person(902, ['https://www.wikidata.org/wiki/Q2', 'https://viaf.org/viaf/123'])),
                $this->linkedValue($this->person(903, [])),
                $this->linkedValue($this->person(904, ['https://www.wikidata.org/wiki/Q4']), false),
                $this->value('Literal, Name'),
            ],
            'dcterms:creator' => [$this->linkedValue($this->person(905, ['https://www.wikidata.org/wiki/Q5']))],
        ]);

        $links = $this->signposting()->links($book, self::PAGE, 'westafrica', self::ORIGIN);

        self::assertSame([
            'https://orcid.org/0000-0003-0959-2092',
            'https://www.wikidata.org/wiki/Q2',
            self::ORIGIN . '/s/westafrica/item/903',
        ], array_column($this->rel($links, 'author'), 'href'), 'a private value and a name alone are not linked; the first role wins');
    }

    public function testDescriptionsFollowTheirEndpointSwitches(): void
    {
        $off = $this->signposting(['iwac_seo_citation_meta' => '0', 'iwac_seo_unapi' => '0'])
            ->links($this->article(), self::PAGE, 'westafrica', self::ORIGIN);
        $person = $this->signposting()->links($this->item(94, []), self::PAGE, 'westafrica', self::ORIGIN);

        self::assertSame([], $this->rel($off, 'describedby'));
        self::assertSame([], $this->rel($person, 'describedby'), 'an authority record has no citation');
        self::assertSame('https://schema.org/Person', $this->rel($person, 'type')[0]['href']);
    }

    public function testAMediaPagePointsAtItsFileAndBackAtItsItem(): void
    {
        $media = $this->mediaFile('application/pdf', self::ORIGIN . '/files/original/article.pdf', true);
        $media->method('item')->willReturn($this->article());

        $links = $this->signposting()->links($media, self::ORIGIN . '/s/westafrica/media/9', 'westafrica', self::ORIGIN);

        self::assertSame('https://schema.org/MediaObject', $this->rel($links, 'type')[0]['href']);
        self::assertSame([[self::ORIGIN . '/files/original/article.pdf', 'application/pdf']], $this->hrefsAndTypes($links, 'item'));
        self::assertSame([self::PAGE], array_column($this->rel($links, 'collection'), 'href'));
        self::assertSame([], $this->rel($links, 'describedby'), 'a file is not a citation');
    }

    public function testHeadMetadataWritesTheSetAsHeadLinks(): void
    {
        $view = $this->renderer(self::PAGE);
        $metadata = $this->headMetadata();

        $metadata->applyResource($view, $this->article(), $this->site('westafrica'));

        $head = html_entity_decode($view->headLink()->toString(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringContainsString('<link href="' . self::PAGE . '" rel="cite-as">', $head);
        self::assertStringContainsString(
            '<link href="' . self::ORIGIN . '/cite/42/bibtex?site=westafrica" rel="describedby" type="application/x-bibtex">',
            $head
        );
        self::assertStringContainsString('<link href="' . self::ORIGIN . '/files/original/article.pdf" rel="item" type="application/pdf">', $head);
        // Beside the canonical and unAPI's own discovery link, every head link is a signpost.
        self::assertCount(substr_count($head, '<link ') - 2, $metadata->signposts());
        self::assertCount(11, $metadata->signposts());
    }

    public function testAnItemSetIsSignpostedOnItsCleanPageOnly(): void
    {
        $page = self::ORIGIN . '/s/westafrica/item-set/7';
        $itemSet = $this->itemSet(7, true);

        $clean = $this->headMetadata();
        $clean->applyBrowse($this->renderer($page), $this->site('westafrica'), $itemSet);
        $paged = $this->headMetadata();
        $paged->applyBrowse($this->renderer($page . '?page=2'), $this->site('westafrica'), $itemSet);
        $listing = $this->headMetadata();
        $listing->applyBrowse($this->renderer(self::ORIGIN . '/s/westafrica/item'), $this->site('westafrica'));

        self::assertSame(['rel' => 'cite-as', 'href' => $page], $clean->signposts()[0]);
        self::assertSame('https://schema.org/Collection', $this->rel($clean->signposts(), 'type')[0]['href']);
        self::assertSame([], $paged->signposts());
        self::assertSame([], $listing->signposts());
    }

    public function testTheLayoutListenerAddsTheLinkHeaderBesideAnotherModules(): void
    {
        $response = new Response();
        // Another module's Link header, set as a raw line before ours.
        $response->getHeaders()->addHeaderLine('Link', '<https://islam.zmo.de/asset/app.css>; rel="preload"');
        $view = $this->renderer(self::PAGE);
        $module = $this->module($view, $response);

        $this->render($module, $view);

        $links = $response->getHeaders()->get('Link');
        self::assertInstanceOf(\ArrayIterator::class, $links, 'both Link headers survive');
        $values = [];
        foreach ($links as $header) {
            self::assertInstanceOf(MultipleHeaderInterface::class, $header, 'each is sent without replacing the other');
            $values[] = $header->getFieldValue();
        }
        self::assertSame('<https://islam.zmo.de/asset/app.css>; rel="preload"', $values[0]);
        self::assertStringStartsWith('<' . self::PAGE . '>; rel="cite-as", <' . self::ORIGIN . '/cite/42/bibtex?site=westafrica>; rel="describedby"; type="application/x-bibtex"', $values[1]);
        self::assertSame(Signposting::header($this->headMetadataOf($module)->signposts()), $values[1]);
    }

    public function testAPageWithoutARecordGetsNoLinkHeader(): void
    {
        $response = new Response();
        $view = $this->renderer(self::ORIGIN . '/s/westafrica/page/about');
        $module = $this->module($view, $response);

        $module->handleLayout(new Event('view.layout', $view));

        self::assertFalse($response->getHeaders()->has('Link'));
    }

    public function testAFailingResponseIsLoggedAndThePageStillRenders(): void
    {
        $view = $this->renderer(self::PAGE);
        $logger = $this->logger();
        $module = $this->module($view, null, $logger);

        $this->render($module, $view);

        self::assertStringContainsString('rel="cite-as"', $view->headLink()->toString(), 'the head links carry the set');
        self::assertSame(['IwacSeo: could not add the Signposting Link header.'], array_column($logger->warnings, 0));
        self::assertInstanceOf(\RuntimeException::class, $logger->warnings[0][1]['exception'] ?? null);
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** @param array<string,string> $settings */
    private function signposting(array $settings = []): Signposting
    {
        return new Signposting(
            new StructuredData([36 => 'NewsArticle', 35 => 'ScholarlyArticle', 40 => 'Book', 94 => 'Person'], 'CreativeWork'),
            new CitationData($this->kinds),
            new ZoteroRdf($this->kinds, new CitationData($this->kinds)),
            new SettingsGate($this->settings($settings)),
        );
    }

    private function headMetadata(): HeadMetadata
    {
        $settings = new SettingsGate($this->settings(['iwac_seo_jsonld_enabled' => '0', 'iwac_seo_citation_meta' => '0']));
        return new HeadMetadata(
            $settings,
            new HeadWriter(),
            new StructuredData([36 => 'NewsArticle'], 'CreativeWork'),
            new CitationMeta($this->kinds),
            new Hreflang(['enabled' => false]),
            new ZoteroRdf($this->kinds, new CitationData($this->kinds)),
            null,
            // The meta tags are off above to keep the head small; the
            // downloads are not, so the describedby links stay.
            $this->signposting(),
        );
    }

    /**
     * The Module with the services its view listeners reach: the head
     * pipeline, and an application whose response is $response — or, with
     * null, one that cannot give a response at all.
     */
    private function module(PhpRenderer $view, ?Response $response, ?object $logger = null): \IwacSeo\Module
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        $view->setVars(['item' => $this->article()]);

        $application = $this->createStub(Application::class);
        if ($response !== null) {
            $application->method('getResponse')->willReturn($response);
        } else {
            $application->method('getResponse')->willThrowException(new \RuntimeException('no response'));
        }

        $services = new ServiceManager();
        $services->setService(HeadMetadata::class, $this->headMetadata());
        $services->setService(SettingsGate::class, new SettingsGate($this->settings([])));
        $services->setService('Application', $application);
        $services->setService('Omeka\Logger', $logger ?? $this->logger());

        $module = new \IwacSeo\Module();
        $module->setServiceLocator($services);
        return $module;
    }

    /**
     * The two listeners of a record page in Omeka's order. The show listener
     * echoes unAPI's <abbr> into the page body, which is kept out of the test
     * output here.
     */
    private function render(\IwacSeo\Module $module, PhpRenderer $view): void
    {
        ob_start();
        try {
            $module->handleResourceShow(new Event('view.show.after', $view));
        } finally {
            $body = (string) ob_get_clean();
        }
        self::assertStringContainsString('class="unapi-id"', $body);
        $module->handleLayout(new Event('view.layout', $view));
    }

    /**
     * A logger that records its warnings. Not Laminas's: constructing one
     * loads its SplPriorityQueue, which PHP 8.5 reports as deprecated outside
     * Omeka's own bootstrap, and a stub of it warns from its destructor.
     */
    private function logger(): object
    {
        return new class {
            /** @var array<int,array{0:string,1:array<string,mixed>}> */
            public array $warnings = [];

            /** @param array<string,mixed> $extra */
            public function warn(string $message, array $extra = []): void
            {
                $this->warnings[] = [$message, $extra];
            }
        };
    }

    private function headMetadataOf(\IwacSeo\Module $module): HeadMetadata
    {
        return $module->getServiceLocator()->get(HeadMetadata::class);
    }

    /**
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    private function rel(array $links, string $rel): array
    {
        return array_values(array_filter($links, static fn (array $link): bool => $link['rel'] === $rel));
    }

    /**
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     * @return array<int,array{0:string,1:?string}>
     */
    private function hrefsAndTypes(array $links, string $rel): array
    {
        return array_map(static fn (array $link): array => [$link['href'], $link['type'] ?? null], $this->rel($links, $rel));
    }

    /** A public newspaper article with a rights statement, files and item sets. */
    private function article(): ItemRepresentation
    {
        return $this->item(36, [
            'dcterms:title' => [$this->value('Une mosquée inaugurée')],
            'dcterms:rights' => [$this->value('In Copyright - Educational Use Permitted', self::RIGHTS)],
        ], [
            $this->mediaFile('application/pdf', self::ORIGIN . '/files/original/article.pdf', true),
            $this->mediaFile('application/pdf', self::ORIGIN . '/files/original/private.pdf', false),
            $this->mediaFile('image/jpeg', self::ORIGIN . '/files/original/scan.jpg', true),
            $this->mediaFile('text/plain', self::ORIGIN . '/files/original/ocr.txt', true),
            $this->mediaFile('video/mp4', null, true),
        ], [$this->itemSet(7, true), $this->itemSet(8, false)]);
    }

    /**
     * @param array<string,array<int,ValueRepresentation>> $values
     * @param array<int,MediaRepresentation> $media
     * @param array<int,ItemSetRepresentation> $itemSets
     */
    private function item(int $classId, array $values, array $media = [], array $itemSets = [], int $id = 42): ItemRepresentation
    {
        $item = $this->createStub(ItemRepresentation::class);
        $item->method('id')->willReturn($id);
        $item->method('isPublic')->willReturn(true);
        $item->method('value')->willReturnCallback(static function (string $term, array $options = []) use ($values) {
            $matches = $values[$term] ?? [];
            return !empty($options['all']) ? $matches : ($matches[0] ?? null);
        });
        $item->method('media')->willReturn($media);
        $item->method('itemSets')->willReturn($itemSets);
        $item->method('resourceClass')->willReturn($this->resourceClass($classId));
        $item->method('displayTitle')->willReturn('Une mosquée inaugurée');
        $item->method('siteUrl')->willReturnCallback(
            static fn (?string $slug = null, bool $canonical = false): string => self::ORIGIN . '/s/' . $slug . '/item/' . $id
        );
        $item->method('primaryMedia')->willReturn(null);
        $item->method('thumbnail')->willReturn(null);
        return $item;
    }

    /**
     * A person authority record carrying $uris in dcterms:identifier, as
     * IWAC's do (Wikidata, VIAF …), beside its opaque internal id.
     *
     * @param string[] $uris
     */
    private function person(int $id, array $uris): ItemRepresentation
    {
        $identifiers = [$this->value('iwac-index-' . $id)];
        foreach ($uris as $uri) {
            $identifiers[] = $this->value($uri, $uri);
        }
        return $this->item(94, ['dcterms:identifier' => $identifiers], id: $id);
    }

    private function itemSet(int $id, bool $public): ItemSetRepresentation
    {
        $itemSet = $this->createStub(ItemSetRepresentation::class);
        $itemSet->method('id')->willReturn($id);
        $itemSet->method('isPublic')->willReturn($public);
        $itemSet->method('value')->willReturnCallback(static fn (string $term, array $options = []) => !empty($options['all']) ? [] : null);
        $itemSet->method('resourceClass')->willReturn(null);
        $itemSet->method('siteUrl')->willReturnCallback(
            static fn (?string $slug = null, bool $canonical = false): string => self::ORIGIN . '/s/' . $slug . '/item-set/' . $id
        );
        return $itemSet;
    }

    /** @return MediaRepresentation&\PHPUnit\Framework\MockObject\Stub */
    private function mediaFile(string $mediaType, ?string $originalUrl, bool $public): MediaRepresentation
    {
        $media = $this->createStub(MediaRepresentation::class);
        $media->method('isPublic')->willReturn($public);
        $media->method('mediaType')->willReturn($mediaType);
        $media->method('originalUrl')->willReturn($originalUrl);
        $media->method('value')->willReturnCallback(static fn (string $term, array $options = []) => !empty($options['all']) ? [] : null);
        $media->method('resourceClass')->willReturn(null);
        return $media;
    }

    private function resourceClass(int $id): ResourceClassRepresentation
    {
        $class = $this->createStub(ResourceClassRepresentation::class);
        $class->method('id')->willReturn($id);
        $class->method('label')->willReturn('Test class');
        return $class;
    }

    private function value(string $text, ?string $uri = null, bool $public = true): ValueRepresentation
    {
        $value = $this->createStub(ValueRepresentation::class);
        $value->method('value')->willReturn($uri === null ? $text : null);
        $value->method('isPublic')->willReturn($public);
        $value->method('lang')->willReturn(null);
        $value->method('__toString')->willReturn($text);
        $value->method('valueResource')->willReturn(null);
        $value->method('uri')->willReturn($uri);
        return $value;
    }

    private function linkedValue(ItemRepresentation $record, bool $public = true): ValueRepresentation
    {
        $value = $this->createStub(ValueRepresentation::class);
        $value->method('isPublic')->willReturn($public);
        $value->method('__toString')->willReturn('A person');
        $value->method('valueResource')->willReturn($record);
        $value->method('uri')->willReturn(null);
        return $value;
    }

    private function site(string $slug): SiteRepresentation
    {
        $site = $this->createStub(SiteRepresentation::class);
        $site->method('slug')->willReturn($slug);
        $site->method('title')->willReturn('Islam West Africa Collection');
        $site->method('isPublic')->willReturn(true);
        return $site;
    }

    /** @param array<string,string> $values */
    private function settings(array $values): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(static fn (string $id, $default = null) => $values[$id] ?? $default);
        return $settings;
    }

    private function renderer(string $currentUrl): PhpRenderer
    {
        $view = new PhpRenderer();
        $helpers = new HelperPluginManager(new ServiceManager());
        foreach (['doctype' => new Doctype(), 'headMeta' => new HeadMeta(), 'headLink' => new HeadLink(), 'headScript' => new HeadScript(), 'headTitle' => new HeadTitle()] as $name => $helper) {
            $helper->setView($view);
            $helpers->setService($name, $helper);
        }
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $currentUrl);
        $helpers->setService(
            'serverUrl',
            static fn (bool|string|null $arg = null): string => is_string($arg) ? $origin . $arg : $currentUrl
        );
        $helpers->setService(
            'url',
            static fn (string $route, array $params = [], array $options = []): string
                => $origin . '/s/' . ($params['site-slug'] ?? 'westafrica') . '/'
        );
        $site = $this->site('westafrica');
        $helpers->setService('currentSite', static fn (): SiteRepresentation => $site);
        $view->setHelperPluginManager($helpers);
        return $view;
    }
}
