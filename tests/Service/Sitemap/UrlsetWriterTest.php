<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service\Sitemap;

use IwacSeo\Service\Sitemap\UrlsetWriter;
use PHPUnit\Framework\TestCase;

/**
 * The sitemap's actual protocol output. This was the module's largest untested
 * surface while it was tangled up with the DBAL queries and the file cache.
 */
final class UrlsetWriterTest extends TestCase
{
    private UrlsetWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new UrlsetWriter();
    }

    private function xml(string $document): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($document);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($parsed, 'document is not well-formed XML');

        return $parsed;
    }

    public function testEmptyUrlsetIsStillValid(): void
    {
        $document = $this->writer->renderUrlset([]);
        $parsed = $this->xml($document);

        $this->assertSame('urlset', $parsed->getName());
        $this->assertCount(0, $parsed->children());
        // Chunk 1 is always served even with no items, so this shape matters.
        $this->assertStringContainsString('http://www.sitemaps.org/schemas/sitemap/0.9', $document);
    }

    public function testUrlCarriesEveryOptionalElement(): void
    {
        $parsed = $this->xml($this->writer->renderUrlset([[
            'loc'        => 'https://islam.zmo.de/s/westafrica/item/42',
            'lastmod'    => '2026-01-02T03:04:05+00:00',
            'changefreq' => 'monthly',
            'priority'   => '0.6',
        ]]));

        $this->assertSame('https://islam.zmo.de/s/westafrica/item/42', (string) $parsed->url[0]->loc);
        $this->assertSame('2026-01-02T03:04:05+00:00', (string) $parsed->url[0]->lastmod);
        $this->assertSame('monthly', (string) $parsed->url[0]->changefreq);
        $this->assertSame('0.6', (string) $parsed->url[0]->priority);
    }

    public function testOptionalElementsAreOmittedWhenNull(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://example.org/item/1',
            'lastmod'    => null,
            'changefreq' => null,
            'priority'   => null,
        ]]);

        $this->assertStringNotContainsString('<lastmod>', $document);
        $this->assertStringNotContainsString('<changefreq>', $document);
        $this->assertStringNotContainsString('<priority>', $document);
    }

    /**
     * An ampersand in a URL (a faceted browse link, a filename) must not break
     * the document — this is why every value goes through esc().
     */
    public function testValuesAreEscaped(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc' => 'https://example.org/item?a=1&b=2',
        ]]);

        $this->assertStringContainsString('a=1&amp;b=2', $document);
        $this->assertSame('https://example.org/item?a=1&b=2', (string) $this->xml($document)->url[0]->loc);
    }

    /** changefreq and priority used to be interpolated raw. */
    public function testConfigSuppliedValuesAreEscapedToo(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://example.org/',
            'changefreq' => 'daily & nightly',
            'priority'   => '<1.0',
        ]]);

        $parsed = $this->xml($document);
        $this->assertSame('daily & nightly', (string) $parsed->url[0]->changefreq);
        $this->assertSame('<1.0', (string) $parsed->url[0]->priority);
    }

    public function testHreflangAlternatesDeclareTheXhtmlNamespace(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'        => 'https://islam.zmo.de/s/westafrica/item/42',
            'alternates' => [
                ['hreflang' => 'fr', 'href' => 'https://islam.zmo.de/s/afrique_ouest/item/42'],
                ['hreflang' => 'en', 'href' => 'https://islam.zmo.de/s/westafrica/item/42'],
                ['hreflang' => 'x-default', 'href' => 'https://islam.zmo.de/s/afrique_ouest/item/42'],
            ],
        ]]);

        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $document);
        $links = $this->xml($document)->url[0]->children('http://www.w3.org/1999/xhtml')->link;
        $this->assertCount(3, $links);
        $this->assertSame('x-default', (string) $links[2]->attributes()->hreflang);
    }

    public function testImageEntriesDeclareTheImageNamespace(): void
    {
        $document = $this->writer->renderUrlset([[
            'loc'   => 'https://example.org/item/1',
            'image' => 'https://example.org/files/large/abc123.jpg',
        ]]);

        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $document);
        $image = $this->xml($document)->url[0]->children('http://www.google.com/schemas/sitemap-image/1.1');
        $this->assertSame('https://example.org/files/large/abc123.jpg', (string) $image->image->loc);
    }

    /** Namespaces are declared only when used, so a plain urlset stays clean. */
    public function testUnusedNamespacesAreNotDeclared(): void
    {
        $document = $this->writer->renderUrlset([['loc' => 'https://example.org/item/1']]);

        $this->assertStringNotContainsString('xmlns:xhtml', $document);
        $this->assertStringNotContainsString('xmlns:image', $document);
    }

    public function testIndexListsChildrenWithOneTimestamp(): void
    {
        $document = $this->writer->renderIndex(
            ['https://islam.zmo.de/sitemap-pages.xml', 'https://islam.zmo.de/sitemap-items-1.xml'],
            '2026-07-25T00:00:00+00:00'
        );
        $parsed = $this->xml($document);

        $this->assertSame('sitemapindex', $parsed->getName());
        $this->assertCount(2, $parsed->sitemap);
        $this->assertSame('https://islam.zmo.de/sitemap-pages.xml', (string) $parsed->sitemap[0]->loc);
        $this->assertSame('2026-07-25T00:00:00+00:00', (string) $parsed->sitemap[1]->lastmod);
    }

    public function testW3cDateParsesDatabaseTimestampsAsUtc(): void
    {
        $this->assertSame('2026-01-02T03:04:05+00:00', UrlsetWriter::w3cDate('2026-01-02 03:04:05'));
        $this->assertNull(UrlsetWriter::w3cDate(null));
        $this->assertNull(UrlsetWriter::w3cDate(''));
        $this->assertNull(UrlsetWriter::w3cDate('not a date'));
    }
}
