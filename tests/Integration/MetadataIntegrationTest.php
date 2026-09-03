<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Service\CitationKindMap;
use IwacSeo\Service\CitationMeta;
use IwacSeo\Service\HeadMetadata;
use IwacSeo\Service\HeadWriter;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\StructuredData;
use IwacSeo\Service\ZoteroRdf;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\Doctype;
use Laminas\View\Helper\HeadLink;
use Laminas\View\Helper\HeadMeta;
use Laminas\View\Helper\HeadScript;
use Laminas\View\Helper\HeadTitle;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ResourceClassRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Settings\Settings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the metadata boundary against Omeka's real representation classes
 * and Laminas's real head-placeholder helpers. The representation instances
 * are PHPUnit fixtures, not local reimplementations of framework classes.
 */
final class MetadataIntegrationTest extends TestCase
{
    private const CANONICAL = 'https://example.test/s/afrique_ouest/item/42';

    private CitationKindMap $kinds;

    protected function setUp(): void
    {
        $this->kinds = new CitationKindMap([36 => 'newspaper'], 'item');
    }

    public function testCitationMetaUsesRealLaminasHeadMetaContainer(): void
    {
        $view = $this->renderer();

        (new CitationMeta($this->kinds))->apply($view, $this->item(), 36, self::CANONICAL);

        $metadata = $this->decoded($view->headMeta()->toString());
        self::assertStringContainsString('name="citation_title"', $metadata);
        self::assertStringContainsString('content="A & B in West Africa"', $metadata);
        self::assertStringContainsString('name="citation_author"', $metadata);
        self::assertStringContainsString('content="Frédérick Madore"', $metadata);
        self::assertStringContainsString('name="prism.publicationName"', $metadata);
        self::assertStringContainsString('content="Fraternité Matin"', $metadata);
        self::assertStringContainsString('name="DC.type" content="newspaperArticle"', $metadata);
        self::assertStringContainsString('name="DC.subject" content="Islam"', $metadata);
        self::assertStringContainsString('name="DC.subject" content="Côte d\'Ivoire"', $metadata);
    }

    public function testZoteroRdfUsesRealOmekaItemAndValueContracts(): void
    {
        $rdf = (new ZoteroRdf($this->kinds))->render($this->item(), self::CANONICAL);

        self::assertNotNull($rdf);
        self::assertStringContainsString('<z:itemType>newspaperArticle</z:itemType>', $rdf);
        self::assertStringContainsString('<dc:title>A &amp; B in West Africa</dc:title>', $rdf);
        self::assertStringContainsString('<dcterms:creator>Frédérick Madore</dcterms:creator>', $rdf);
        self::assertStringContainsString('<prism:publicationName>Fraternité Matin</prism:publicationName>', $rdf);
        self::assertStringContainsString('<bib:pages>3-4</bib:pages>', $rdf);
        self::assertStringContainsString('<rdf:value>iwac-article-0000042</rdf:value>', $rdf);
        self::assertStringContainsString(self::CANONICAL, $rdf);
    }

    public function testHeadMetadataWritesThroughRealLaminasHelpers(): void
    {
        $view = $this->renderer();
        $settings = $this->settings([
            'iwac_seo_jsonld_enabled' => '0',
            'iwac_seo_citation_meta' => '1',
            'iwac_seo_unapi' => '0',
        ]);
        $head = new HeadWriter();
        $metadata = new HeadMetadata(
            new SettingsGate($settings),
            $head,
            new StructuredData([], 'CreativeWork'),
            new CitationMeta($this->kinds),
            new Hreflang(['enabled' => false]),
            new ZoteroRdf($this->kinds),
        );

        $body = $metadata->applyResource($view, $this->item(), $this->site());

        self::assertNull($body);
        $headMeta = $this->decoded($view->headMeta()->toString());
        self::assertStringContainsString('name="description"', $headMeta);
        self::assertStringContainsString('A concise newspaper summary.', $headMeta);
        self::assertStringContainsString('property="og:title" content="A & B in West Africa"', $headMeta);
        self::assertStringContainsString('name="citation_title"', $headMeta);

        $headLink = $this->decoded($view->headLink()->toString());
        self::assertStringContainsString('rel="canonical"', $headLink);
        self::assertStringContainsString(self::CANONICAL, $headLink);
    }

    public function testJsonLdNoEscapeOptionDoesNotLeakToLaterScripts(): void
    {
        $view = $this->renderer();
        $writer = new HeadWriter();

        $writer->jsonLd($view, ['@context' => 'https://schema.org', 'name' => 'A & B']);
        $view->headScript()->appendScript('const label = "A & B";');
        $scripts = $this->decoded($view->headScript()->toString());

        self::assertStringContainsString('type="application/ld+json"', $scripts);
        self::assertMatchesRegularExpression(
            '#<script type="application/ld\+json">\s*\{.*"name": "A & B".*</script>#s',
            $scripts
        );
        self::assertMatchesRegularExpression(
            '#<script[^>]*>\s*//(?:<!\[CDATA\[|<!--).*const label = "A & B";#s',
            $scripts
        );
    }

    public function testLayoutGapFillCanonicalisesQueryVariantsToTheBarePage(): void
    {
        // /s/{site}/search is IwacSearch's controller, so no phase-1 listener
        // claims it and only the layout pass runs. Every facet permutation used
        // to emit a self-referential canonical and no robots directive, which
        // is what let ~1,300 legacy facet URLs into the index.
        $search = 'https://example.test/s/afrique_ouest/search';
        $view = $this->renderer($search . "?facet%5Bdcterms_type_ss%5D%5B9%5D=Article d'encyclopédie&page=2");

        $this->metadata(['iwac_seo_noindex_browse' => '1'])->applyGlobals($view, $this->site());

        $headLink = $this->decoded($view->headLink()->toString());
        self::assertStringContainsString('rel="canonical"', $headLink);
        self::assertStringContainsString('href="' . $search . '"', $headLink);
        self::assertStringNotContainsString('facet', $headLink);

        $headMeta = $this->decoded($view->headMeta()->toString());
        self::assertStringContainsString('name="robots" content="noindex, follow"', $headMeta);
        // og:url is the page's identity when shared; it must not disagree.
        self::assertStringContainsString('property="og:url" content="' . $search . '"', $headMeta);
    }

    public function testLayoutGapFillLeavesAQuerylessPageIndexable(): void
    {
        $search = 'https://example.test/s/afrique_ouest/search';
        $view = $this->renderer($search);

        $this->metadata(['iwac_seo_noindex_browse' => '1'])->applyGlobals($view, $this->site());

        self::assertStringContainsString(
            'href="' . $search . '"',
            $this->decoded($view->headLink()->toString())
        );
        self::assertStringNotContainsString('name="robots"', $this->decoded($view->headMeta()->toString()));
    }

    public function testBrowseKeepsTheSelfReferentialCanonicalAndNoindexesTheVariant(): void
    {
        // Browse pagination is a genuine series: page 2 must not collapse onto
        // page 1, so the canonical here stays self-referential — noindex, not
        // the canonical, is what keeps the variant out of the index.
        $paged = 'https://example.test/s/afrique_ouest/item?page=2';
        $view = $this->renderer($paged);

        $this->metadata(['iwac_seo_noindex_browse' => '1'])->applyBrowse($view, $this->site());

        self::assertStringContainsString('href="' . $paged . '"', $this->decoded($view->headLink()->toString()));
        self::assertStringContainsString(
            'name="robots" content="noindex, follow"',
            $this->decoded($view->headMeta()->toString())
        );
    }

    public function testResourceCanonicalSurvivesTheLayoutGapFill(): void
    {
        // A paginated resource page (?page=N on the linked-resources table)
        // canonicalises to the resource itself, and phase 2 must not touch it.
        $view = $this->renderer(self::CANONICAL . '?page=380');
        $metadata = $this->metadata([
            'iwac_seo_jsonld_enabled' => '0',
            'iwac_seo_citation_meta'  => '0',
            'iwac_seo_unapi'          => '0',
            'iwac_seo_noindex_browse' => '1',
        ]);

        $metadata->applyResource($view, $this->item(), $this->site());
        $metadata->applyGlobals($view, $this->site());

        $headLink = $this->decoded($view->headLink()->toString());
        self::assertStringContainsString('href="' . self::CANONICAL . '"', $headLink);
        self::assertStringNotContainsString('page=380', $headLink);
        self::assertStringNotContainsString('name="robots"', $this->decoded($view->headMeta()->toString()));
    }

    public function testEventIntervalBecomesStartDateAndEndDate(): void
    {
        // Class 99 stands for an installation that has remapped a class to
        // Event in local.config.php — the only way the Event branch is now
        // reached, IWAC's own events being DefinedTerm. 55 of IWAC's 243 event
        // records hold an interval, and Google rejects one handed whole to
        // startDate as "not in ISO 8601 format".
        $data = $this->structuredData()->forResource(
            $this->resource(99, ['dcterms:date' => '1979-11-04/1981-01-20', 'dcterms:spatial' => 'Burkina Faso']),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertSame('Event', $data['@type']);
        self::assertSame('1979-11-04', $data['startDate']);
        self::assertSame('1981-01-20', $data['endDate']);
        self::assertSame([['@type' => 'Place', 'name' => 'Burkina Faso']], $data['location']);
    }

    public function testEventWithASingleDateGetsNoEndDate(): void
    {
        $data = $this->structuredData()->forResource(
            $this->resource(99, ['dcterms:date' => '1997']),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertSame('1997', $data['startDate']);
        self::assertArrayNotHasKey('endDate', $data);
    }

    public function testEventDropsADateNoValidatorCouldRead(): void
    {
        $data = $this->structuredData()->forResource(
            $this->resource(99, ['dcterms:date' => 'vers 1997', 'dcterms:spatial' => 'Burkina Faso']),
            $this->site(),
            self::CANONICAL,
            null
        );

        // Asserted against a node that is genuinely an Event, so the absence
        // means the date was rejected rather than the branch never running.
        self::assertSame('Event', $data['@type']);
        self::assertArrayHasKey('location', $data);
        self::assertArrayNotHasKey('startDate', $data);
        self::assertArrayNotHasKey('endDate', $data);
    }

    public function testBookReviewIsAScholarlyArticleAboutTheBook(): void
    {
        // Not a schema.org Review: Google's review snippet requires a
        // reviewRating that an academic book review does not have, so the type
        // would only ever be reported invalid.
        $data = $this->structuredData()->forResource(
            $this->resource(178, [
                'dcterms:title' => 'Brégand, Denise. -- Commerce caravanier…',
                'bibo:reviewOf' => 'Commerce caravanier et relations sociales au Bénin',
            ]),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertSame('ScholarlyArticle', $data['@type']);
        self::assertArrayNotHasKey('itemReviewed', $data);
        self::assertSame(
            ['@type' => 'Book', 'name' => 'Commerce caravanier et relations sociales au Bénin'],
            $data['about']
        );
    }

    public function testAnOrdinaryArticleGetsNoAboutNode(): void
    {
        // about is keyed on bibo:reviewOf, not on the type, because book
        // reviews now share ScholarlyArticle with ordinary journal articles.
        $data = $this->structuredData()->forResource(
            $this->resource(35, ['dcterms:title' => 'An ordinary journal article']),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertArrayNotHasKey('about', $data);
    }

    public function testVideoThumbnailComesFromTheItemNotTheSiteDefault(): void
    {
        $own = 'https://example.test/files/large/abc.jpg';
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2022-07-01']),
            $this->site(),
            self::CANONICAL,
            $own,
            $own
        );

        self::assertSame($own, $data['thumbnailUrl']);
        self::assertSame('2022-07-01T00:00:00+00:00', $data['uploadDate']);
    }

    public function testVideoWithoutItsOwnThumbnailClaimsNone(): void
    {
        // image may fall back to the site's default share graphic; thumbnailUrl
        // may not — it would tell Google the logo is a still from the video.
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2022-07-01']),
            $this->site(),
            self::CANONICAL,
            'https://example.test/files/asset/site-default.webp',
            null
        );

        self::assertArrayNotHasKey('thumbnailUrl', $data);
        self::assertSame('https://example.test/files/asset/site-default.webp', $data['image']);
    }

    public function testHostedVideoIsEmbeddableFromItsSourceUrl(): void
    {
        // 1,743 of the 1,790 videos: fabio:hasURL names the YouTube watch page,
        // and embedUrl wants the player behind it.
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2021-08-21'], [], [], [
                'fabio:hasURL' => 'https://www.youtube.com/watch?v=UENBMWutb-w',
            ]),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertSame('https://www.youtube.com/embed/UENBMWutb-w', $data['embedUrl']);
        self::assertArrayNotHasKey('contentUrl', $data);
    }

    public function testVideoSourceThatIsNotAPlayerYieldsNoEmbedUrl(): void
    {
        // The archive's SoundCloud track and Wayback capture: a URL that does
        // not resolve to a player is worse than no embedUrl at all.
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2021-08-21'], [], [], [
                'fabio:hasURL' => 'https://soundcloud.com/radio-omega/libertes-religieuses',
            ]),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertArrayNotHasKey('embedUrl', $data);
    }

    public function testVideoWithNoSourceAtAllClaimsNoPlaybackUrl(): void
    {
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2021-08-21']),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertArrayNotHasKey('embedUrl', $data);
        self::assertArrayNotHasKey('contentUrl', $data);
    }

    public function testDigitisedVideoNamesTheFileItServes(): void
    {
        // The 44 DVD transfers hold no source URL; the video file is the item's
        // own media, which is what contentUrl asks for.
        $file = 'https://example.test/files/original/626a1e.mp4';
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2019'], [], [
                $this->media('image/jpeg', 'https://example.test/files/original/cover.jpg'),
                $this->media('video/mp4', $file),
            ]),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertSame($file, $data['contentUrl']);
        self::assertArrayNotHasKey('embedUrl', $data);
        // A year is all the archive holds; uploadDate is required all the same.
        self::assertSame('2019-01-01T00:00:00+00:00', $data['uploadDate']);
    }

    public function testVideoIgnoresMediaThatAreNotThePlayableFile(): void
    {
        // contentUrl must point at content bytes: a cover image is not the
        // video, and a private media is not servable to the crawler at all.
        $data = $this->structuredData()->forResource(
            $this->resource(38, ['dcterms:date' => '2019-08-11'], [], [
                $this->media('image/jpeg', 'https://example.test/files/original/cover.jpg'),
                $this->media('video/mp4', 'https://example.test/files/original/private.mp4', false),
                $this->media('video/mp4', null),
            ]),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertArrayNotHasKey('contentUrl', $data);
    }

    public function testNonVideoTypesGetNoPlaybackUrls(): void
    {
        $data = $this->structuredData()->forResource(
            $this->resource(178, ['dcterms:date' => '2019-08-11'], [], [
                $this->media('video/mp4', 'https://example.test/files/original/x.mp4'),
            ]),
            $this->site(),
            self::CANONICAL,
            null,
            null
        );

        self::assertSame('ScholarlyArticle', $data['@type']);
        self::assertArrayNotHasKey('contentUrl', $data);
        self::assertArrayNotHasKey('uploadDate', $data);
    }

    public function testConferencePaperNamesItsEventByUrlWhenTheEventIsARecord(): void
    {
        // isPartOf ranges over CreativeWork or URL. A linked event record has a
        // URL, so the cross-link survives without claiming a type.
        $data = $this->structuredData()->forResource(
            $this->resource(77, ['dcterms:date' => '2022-09-16'], [
                'dcterms:isPartOf' => ['Leibniz-Zentrum Moderner Orient Open Day', 186],
            ]),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertSame('https://example.test/s/afrique_ouest/item/186', $data['isPartOf']);
    }

    public function testConferencePaperOmitsAnEventThatIsOnlyALiteralTitle(): void
    {
        // Ten of the nineteen hold the event as a bare string. There is no
        // in-range way to say that, and a name Google cannot dereference is not
        // worth a range violation.
        $data = $this->structuredData()->forResource(
            $this->resource(77, [
                'dcterms:date'       => '2022-09-16',
                'dcterms:isPartOf'   => 'Leibniz-Zentrum Moderner Orient Open Day',
                'dcterms:provenance' => 'Berlin',
            ]),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertArrayNotHasKey('isPartOf', $data);
    }

    public function testEventAuthorityRecordIsADefinedTermNotAnEvent(): void
    {
        // Google's Event feature is for events bookable by the public; a 1997
        // congress is ineligible by that guideline however complete its record.
        $data = $this->structuredData()->forResource(
            $this->resource(54, ['dcterms:title' => 'Congrès OJEMAO (1997)', 'dcterms:date' => '1997']),
            $this->site(),
            self::CANONICAL,
            null
        );

        self::assertSame('DefinedTerm', $data['@type']);
        self::assertArrayNotHasKey('startDate', $data);
        self::assertArrayNotHasKey('location', $data);
    }

    private function structuredData(): StructuredData
    {
        return new StructuredData(
            [54 => 'DefinedTerm', 77 => 'CreativeWork', 38 => 'VideoObject',
                178 => 'ScholarlyArticle', 35 => 'ScholarlyArticle', 99 => 'Event'],
            'CreativeWork'
        );
    }

    /**
     * A resource mock carrying exactly $values, for the JSON-LD shape tests.
     *
     * @param array<string,string> $values term => single literal value
     * @param array<string,array{0:string,1:int}> $links term => [title, item id]
     *   for values that point at another record rather than holding a literal
     * @param array<MediaRepresentation&MockObject> $media the item's media, in order
     * @param array<string,string> $uris term => URI, for the uri-typed values
     *   (fabio:hasURL, the Wikidata dcterms:identifier) whose payload is the
     *   link rather than the text
     * @return ItemRepresentation&MockObject
     */
    private function resource(
        int $classId,
        array $values,
        array $links = [],
        array $media = [],
        array $uris = []
    ): ItemRepresentation {
        $class = $this->getMockBuilder(ResourceClassRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id', 'label'])
            ->getMock();
        $class->method('id')->willReturn($classId);
        $class->method('label')->willReturn('Test class');

        $wrapped = [];
        foreach ($values as $term => $text) {
            $wrapped[$term] = [$this->value($text)];
        }
        foreach ($links as $term => [$title, $linkedId]) {
            $wrapped[$term] = [$this->linkedValue($title, $linkedId)];
        }
        foreach ($uris as $term => $uri) {
            $wrapped[$term] = [$this->value($uri, $uri)];
        }

        $item = $this->getMockBuilder(ItemRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['value', 'media', 'resourceClass', 'displayTitle', 'siteUrl', 'primaryMedia'])
            ->getMock();
        $item->method('value')->willReturnCallback(
            static function (string $term, array $options = []) use ($wrapped) {
                $matches = $wrapped[$term] ?? [];
                return !empty($options['all']) ? $matches : ($matches[0] ?? null);
            }
        );
        $item->method('media')->willReturn($media);
        $item->method('resourceClass')->willReturn($class);
        $item->method('displayTitle')->willReturn($values['dcterms:title'] ?? 'Untitled');
        $item->method('siteUrl')->willReturn(self::CANONICAL);
        $item->method('primaryMedia')->willReturn(null);
        return $item;
    }

    private function renderer(string $currentUrl = self::CANONICAL): PhpRenderer
    {
        $view = new PhpRenderer();
        $helpers = new HelperPluginManager(new ServiceManager());
        $instances = [
            'doctype' => new Doctype(),
            'headMeta' => new HeadMeta(),
            'headLink' => new HeadLink(),
            'headScript' => new HeadScript(),
            'headTitle' => new HeadTitle(),
        ];
        foreach ($instances as $name => $helper) {
            $helper->setView($view);
            $helpers->setService($name, $helper);
        }
        // Laminas's real ServerUrl helper reads $_SERVER; the contract the
        // module depends on is just serverUrl(true) === "the URL being served",
        // and serverUrl('/path') === that path made absolute.
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $currentUrl);
        $helpers->setService(
            'serverUrl',
            static fn (bool|string|null $arg = null): string => is_string($arg) ? $origin . $arg : $currentUrl
        );
        $view->setHelperPluginManager($helpers);
        return $view;
    }

    /** @param array<string,mixed> $settings */
    private function metadata(array $settings): HeadMetadata
    {
        return new HeadMetadata(
            new SettingsGate($this->settings($settings)),
            new HeadWriter(),
            new StructuredData([], 'CreativeWork'),
            new CitationMeta($this->kinds),
            new Hreflang(['enabled' => false]),
            new ZoteroRdf($this->kinds),
        );
    }

    private function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return ItemRepresentation&MockObject */
    private function item(): ItemRepresentation
    {
        $values = [
            'dcterms:title' => [$this->value('A & B in West Africa')],
            'dcterms:creator' => [$this->value('Frédérick Madore')],
            'dcterms:date' => [$this->value('2025-05-13')],
            'dcterms:publisher' => [$this->value('Fraternité Matin')],
            'dcterms:language' => [$this->value('Français')],
            'dcterms:subject' => [$this->value('Islam')],
            'dcterms:spatial' => [$this->value("Côte d'Ivoire")],
            'dcterms:abstract' => [$this->value('A formal abstract.')],
            'bibo:shortDescription' => [$this->value('A concise newspaper summary.')],
            'bibo:issue' => [$this->value('1234')],
            'bibo:pageStart' => [$this->value('3')],
            'bibo:pageEnd' => [$this->value('4')],
            'dcterms:identifier' => [$this->value('iwac-article-0000042')],
        ];

        $class = $this->getMockBuilder(ResourceClassRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id', 'label'])
            ->getMock();
        $class->method('id')->willReturn(36);
        $class->method('label')->willReturn('Newspaper article');

        $item = $this->getMockBuilder(ItemRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['value', 'media', 'resourceClass', 'displayTitle', 'siteUrl', 'primaryMedia'])
            ->getMock();
        $item->method('value')->willReturnCallback(
            static function (string $term, array $options = []) use ($values) {
                $matches = $values[$term] ?? [];
                return !empty($options['all']) ? $matches : ($matches[0] ?? null);
            }
        );
        $item->method('media')->willReturn([]);
        $item->method('resourceClass')->willReturn($class);
        $item->method('displayTitle')->willReturn('A & B in West Africa');
        $item->method('siteUrl')->willReturnCallback(
            static function (string $slug, bool $canonical = false): string {
                return 'https://example.test/s/' . $slug . '/item/42';
            }
        );
        $item->method('primaryMedia')->willReturn(null);
        return $item;
    }

    /**
     * A value pointing at another record, so firstLink() resolves a URL.
     *
     * @return ValueRepresentation&MockObject
     */
    private function linkedValue(string $title, int $linkedId): ValueRepresentation
    {
        $linked = $this->getMockBuilder(ItemRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['displayTitle', 'siteUrl'])
            ->getMock();
        $linked->method('displayTitle')->willReturn($title);
        $linked->method('siteUrl')->willReturnCallback(
            static fn (string $slug, bool $canonical = false): string
                => 'https://example.test/s/' . $slug . '/item/' . $linkedId
        );

        $value = $this->getMockBuilder(ValueRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__toString', 'valueResource', 'uri'])
            ->getMock();
        $value->method('__toString')->willReturn($title);
        $value->method('valueResource')->willReturn($linked);
        $value->method('uri')->willReturn(null);
        return $value;
    }

    /** @return ValueRepresentation&MockObject */
    /**
     * A media mock for the contentUrl tests: the 44 DVD-digitised videos hold
     * the file itself, so what matters is its type, its visibility and its URL.
     *
     * @return MediaRepresentation&MockObject
     */
    private function media(string $mediaType, ?string $originalUrl, bool $public = true): MediaRepresentation
    {
        $media = $this->getMockBuilder(MediaRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isPublic', 'mediaType', 'originalUrl'])
            ->getMock();
        $media->method('isPublic')->willReturn($public);
        $media->method('mediaType')->willReturn($mediaType);
        $media->method('originalUrl')->willReturn($originalUrl);
        return $media;
    }

    private function value(string $text, ?string $uri = null): ValueRepresentation
    {
        $value = $this->getMockBuilder(ValueRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__toString', 'valueResource', 'uri'])
            ->getMock();
        $value->method('__toString')->willReturn($text);
        $value->method('valueResource')->willReturn(null);
        $value->method('uri')->willReturn($uri);
        return $value;
    }

    /** @return SiteRepresentation&MockObject */
    private function site(): SiteRepresentation
    {
        $site = $this->getMockBuilder(SiteRepresentation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['slug', 'title'])
            ->getMock();
        $site->method('slug')->willReturn('afrique_ouest');
        $site->method('title')->willReturn("Collection Islam Afrique de l'Ouest");
        return $site;
    }

    /** @param array<string,mixed> $values */
    private function settings(array $values): Settings
    {
        $settings = $this->getMockBuilder(Settings::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $settings->method('get')->willReturnCallback(
            static fn (string $id, $default = null) => $values[$id] ?? $default
        );
        return $settings;
    }
}
