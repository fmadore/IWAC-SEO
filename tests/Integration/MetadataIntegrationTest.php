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

    /** @return ValueRepresentation&MockObject */
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
