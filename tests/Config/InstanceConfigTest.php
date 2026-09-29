<?php
declare(strict_types=1);

namespace IwacSeo\Test\Config;

use IwacSeo\Service\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * IWAC-specific class ids and bilingual page-map invariants.
 *
 * @phpstan-type InstanceConfig array{
 *     sitemap:array{item_chunk_size:int},
 *     structured_data:array{class_types:array<int,string>},
 *     citation:array{class_kinds:array<int,string>},
 *     robots:array{disallow:string[],allow:string[]},
 *     hreflang:array{
 *         sites:array<string,string>,
 *         x_default:string,
 *         page_pairs:array<array<string,string>>
 *     }
 * }
 */
final class InstanceConfigTest extends TestCase
{
    /** @var InstanceConfig */
    private array $config;

    protected function setUp(): void
    {
        /** @var array{iwac_seo:InstanceConfig} $config */
        $config = include dirname(__DIR__, 2) . '/config/instance.config.php';
        $this->config = $config['iwac_seo'];
    }

    public function testStructuredDataAndCitationMapsCoverTheSameKnownClasses(): void
    {
        $classTypes = $this->config['structured_data']['class_types'];
        $classKinds = $this->config['citation']['class_kinds'];
        $expected = [9, 35, 36, 38, 40, 43, 49, 52, 54, 58, 60, 77, 82, 88, 94, 96, 178, 244, 305];

        $typeIds = array_keys($classTypes);
        $kindIds = array_keys($classKinds);
        sort($typeIds);
        sort($kindIds);

        $this->assertSame($expected, $typeIds);
        $this->assertSame($expected, $kindIds);
        $this->assertSame('NewsArticle', $classTypes[36]);
        $this->assertSame('newspaper', $classKinds[36]);
        $this->assertSame('PublicationIssue', $classTypes[60]);
        $this->assertSame('periodical-issue', $classKinds[60]);
        // Not 'Event': Google's Event feature is for events bookable by the
        // public, which a historical congress can never be. The citation kind
        // stays 'event' — that side has no such eligibility rule.
        $this->assertSame('DefinedTerm', $classTypes[54]);
        $this->assertSame('event', $classKinds[54]);
        $this->assertSame('ImageObject', $classTypes[58]);
        $this->assertSame('photo', $classKinds[58]);
        // Book reviews are ScholarlyArticle, not Review: Google's review
        // snippet requires a reviewRating an academic review never awards, so
        // the type would be reported invalid forever. The citation kind stays
        // 'review' — that one drives Zotero, which has a review item type.
        $this->assertSame('ScholarlyArticle', $classTypes[178]);
        $this->assertSame('review', $classKinds[178]);
    }

    public function testAllNineReferenceClassesAreMapped(): void
    {
        $referenceClasses = [35, 40, 43, 52, 77, 82, 88, 178, 305];
        $classKinds = $this->config['citation']['class_kinds'];

        foreach ($referenceClasses as $classId) {
            $this->assertArrayHasKey($classId, $classKinds);
        }
    }

    public function testEveryHreflangPairIsCompleteUniqueAndHasAnXDefaultSite(): void
    {
        $hreflang = $this->config['hreflang'];
        $siteSlugs = array_keys($hreflang['sites']);
        $this->assertContains($hreflang['x_default'], $siteSlugs);

        /** @var array<string,array<string,bool>> $seen */
        $seen = array_fill_keys($siteSlugs, []);
        foreach ($hreflang['page_pairs'] as $pair) {
            $this->assertSame($siteSlugs, array_keys($pair));
            foreach ($siteSlugs as $siteSlug) {
                $pageSlug = trim((string) $pair[$siteSlug]);
                $this->assertNotSame('', $pageSlug);
                $this->assertArrayNotHasKey(
                    $pageSlug,
                    $seen[$siteSlug],
                    sprintf('Duplicate hreflang slug for %s: %s', $siteSlug, $pageSlug)
                );
                $seen[$siteSlug][$pageSlug] = true;
            }
        }
    }

    public function testSitemapChunkSizeRespectsTheProtocolLimit(): void
    {
        $size = (int) $this->config['sitemap']['item_chunk_size'];
        $this->assertGreaterThan(0, $size);
        $this->assertLessThanOrEqual(50000, $size);
    }

    /**
     * The crawl rules must block query variants of every search surface and
     * browse route, and nothing that is meant to be indexed: not the landing
     * pages, not clean pagination, not any URL shape the sitemap lists.
     */
    public function testRobotsRulesBlockQueryVariantsButNoIndexablePage(): void
    {
        $robots = new RobotsTxt($this->config['robots']['disallow'], $this->config['robots']['allow']);

        $blocked = [
            '/search?q=hadj',
            '/search/everything?tab=entities',
            '/s/westafrica/search?q=hajj&f.country_ss=Benin',
            '/s/westafrica/search/everything?q=x',
            '/s/afrique_ouest/recherche?f.country_ss=B%C3%A9nin',
            '/s/afrique_ouest/recherche/tout?tab=entities',
            '/s/afrique_ouest/item?property%5B0%5D%5Bproperty%5D=3&property%5B0%5D%5Btext%5D=Islam',
            '/s/afrique_ouest/item?item_set_id=5',
            '/s/afrique_ouest/item?sort_by=title&page=2',
            '/s/westafrica/item-set?sort_by=created',
            '/s/westafrica/media?resource_class_id=36',
            '/discovery/token',
        ];
        foreach ($blocked as $path) {
            self::assertFalse($robots->allows($path), $path);
        }

        $crawlable = [
            '/search',
            '/search/everything',
            '/s/westafrica/search',
            '/s/afrique_ouest/recherche',
            '/s/afrique_ouest/recherche/tout',
            '/s/afrique_ouest/item',
            '/s/afrique_ouest/item?page=3',
            '/s/westafrica/item-set?page=2',
            // Every URL shape the sitemaps list.
            '/s/afrique_ouest/item/2231',
            '/s/westafrica/item-set/12',
            '/s/afrique_ouest/page/accueil',
            '/s/westafrica/page/home',
            '/sitemap.xml',
            '/files/large/abc.jpg',
        ];
        foreach ($crawlable as $path) {
            self::assertTrue($robots->allows($path), $path);
        }
    }
}
