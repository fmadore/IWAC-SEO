<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Sitemap\UrlsetWriter;
use IwacSeo\Service\Sitemap\XmlCache;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Test\Double\InMemorySitemapRepository;
use PHPUnit\Framework\TestCase;

/** The generator's policy: chunking, ordering and URL construction. */
final class SitemapGeneratorTest extends TestCase
{
    private InMemorySitemapRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemorySitemapRepository();
    }

    public function testIndexListsOneChildPerItemChunk(): void
    {
        $this->repository->itemCount = 5;
        $generator = $this->generator(['item_chunk_size' => 2]);

        $locations = $this->values($generator->buildIndex('https://islam.zmo.de', 7, 0)->xml, '//sm:loc');

        $this->assertSame([
            'https://islam.zmo.de/sitemap-pages.xml',
            'https://islam.zmo.de/sitemap-item-sets.xml',
            'https://islam.zmo.de/sitemap-items-1.xml',
            'https://islam.zmo.de/sitemap-items-2.xml',
            'https://islam.zmo.de/sitemap-items-3.xml',
        ], $locations);
    }

    public function testPagesFollowHomepageThenNavigationThenUnlistedOrder(): void
    {
        $this->repository->pages = [
            ['id' => 1, 'slug' => 'accueil', 'modified' => '2026-01-01 00:00:00'],
            ['id' => 2, 'slug' => 'collections', 'modified' => null],
            ['id' => 3, 'slug' => 'exposition spéciale', 'modified' => null],
            ['id' => 4, 'slug' => 'recherche', 'modified' => null],
        ];
        $navigation = [[
            'type'  => 'page',
            'data'  => ['id' => 2],
            'links' => [[
                'type' => 'page',
                'data' => ['id' => 3],
            ]],
        ], [
            // A repeated menu entry must not duplicate the canonical URL.
            'type' => 'page',
            'data' => ['id' => 2],
        ]];
        $generator = $this->generator([
            'priority' => ['home' => '1.0', 'section' => '0.8', 'page' => '0.5', 'browse' => '0.4'],
            'changefreq' => ['home' => 'daily', 'page' => 'monthly'],
        ]);

        $document = $generator->buildPages(
            'https://islam.zmo.de/s/afrique_ouest',
            7,
            0,
            $navigation,
            1
        )->xml;

        $this->assertSame([
            'https://islam.zmo.de/s/afrique_ouest/page/accueil',
            'https://islam.zmo.de/s/afrique_ouest/page/collections',
            'https://islam.zmo.de/s/afrique_ouest/page/exposition%20sp%C3%A9ciale',
            'https://islam.zmo.de/s/afrique_ouest/page/recherche',
        ], $this->values($document, '//sm:loc'));
        $this->assertSame(['1.0', '0.8', '0.5', '0.4'], $this->values($document, '//sm:priority'));
    }

    public function testItemChunkCarriesImagesAndReciprocalAlternates(): void
    {
        $this->repository->items = [
            ['id' => 1, 'modified' => null],
            ['id' => 2, 'modified' => null],
            ['id' => 3, 'modified' => '2026-02-03 04:05:06', 'storage_id' => 'scan-3'],
            ['id' => 4, 'modified' => null],
        ];
        $generator = $this->generator([
            'item_chunk_size' => 2,
            'include_images'  => true,
            'priority'        => ['item' => '0.6'],
            'changefreq'      => ['item' => 'monthly'],
        ]);
        $alternates = [
            ['lang' => 'fr', 'base' => 'https://islam.zmo.de/s/afrique_ouest'],
            ['lang' => 'en', 'base' => 'https://islam.zmo.de/s/westafrica'],
        ];

        $document = $generator->buildItems(
            'https://islam.zmo.de/s/westafrica',
            7,
            2,
            0,
            $alternates,
            'https://islam.zmo.de/s/afrique_ouest'
        )->xml;

        $this->assertSame([
            'siteId' => 7,
            'offset' => 2,
            'limit' => 2,
            'withImages' => true,
        ], $this->repository->lastItemFetch);
        $this->assertSame([
            'https://islam.zmo.de/s/westafrica/item/3',
            'https://islam.zmo.de/s/westafrica/item/4',
        ], $this->values($document, '//sm:loc'));
        $this->assertSame(
            ['https://islam.zmo.de/files/large/scan-3.jpg'],
            $this->values($document, '//image:loc')
        );
        $this->assertCount(6, $this->nodes($document, '//xhtml:link'));
    }

    public function testConfiguredFileBaseUriIsUsedForImages(): void
    {
        $this->repository->items = [[
            'id' => 42,
            'modified' => null,
            'storage_id' => 'cover',
        ]];
        $generator = $this->generator(['include_images' => true], 'https://media.example/files/');

        $document = $generator->buildItems('https://islam.zmo.de/s/westafrica', 7, 1, 0)->xml;

        $this->assertSame(
            ['https://media.example/files/large/cover.jpg'],
            $this->values($document, '//image:loc')
        );
    }

    /** @param array<string,mixed> $config */
    private function generator(array $config, ?string $fileBaseUri = null): SitemapGenerator
    {
        return new SitemapGenerator(
            $this->repository,
            new UrlsetWriter(),
            new XmlCache(null),
            $config,
            $fileBaseUri,
        );
    }

    /** @return \SimpleXMLElement[] */
    private function nodes(string $document, string $expression): array
    {
        $xml = simplexml_load_string($document);
        $this->assertNotFalse($xml, 'document is not well-formed XML');
        $xml->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
        $xml->registerXPathNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        return $xml->xpath($expression) ?: [];
    }

    /** @return string[] */
    private function values(string $document, string $expression): array
    {
        return array_map(
            static fn (\SimpleXMLElement $node): string => (string) $node,
            $this->nodes($document, $expression)
        );
    }
}
