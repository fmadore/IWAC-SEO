<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\UrlPolicy;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase
{
    public function testTrackingIsRemovedAndPaginationPreserved(): void
    {
        self::assertSame('https://example.test/items?page=2', UrlPolicy::canonical('https://example.test/items?utm_source=email&page=2#top'));
        self::assertFalse(UrlPolicy::isFiltered('https://example.test/items?page=2&utm_source=email'));
        self::assertSame('https://example.test/items', UrlPolicy::canonical('https://example.test/items?page=1&gclid=abc'));
    }

    public function testOwnHostAcceptsTheRequestHostOrThePinnedPublicOrigin(): void
    {
        $id = 'https://islam.zmo.de/s/afrique_ouest/item/2231';
        self::assertTrue(UrlPolicy::isOwnHost($id, 'ISLAM.zmo.de'));
        self::assertFalse(UrlPolicy::isOwnHost($id, 'omeka.internal'));
        self::assertFalse(UrlPolicy::isOwnHost('/s/afrique_ouest/item/2231', 'islam.zmo.de'));

        putenv('IWAC_SEO_PUBLIC_ORIGIN=https://islam.zmo.de');
        try {
            // Behind a proxy that rewrites Host, the canonical still names the public origin.
            self::assertTrue(UrlPolicy::isOwnHost($id, 'omeka.internal'));
            self::assertFalse(UrlPolicy::isOwnHost('https://evil.example/s/x/item/1', 'omeka.internal'));
        } finally {
            putenv('IWAC_SEO_PUBLIC_ORIGIN');
        }
    }

    public function testPublicOriginRewritesUrlsAndAMalformedOneIsIgnoredNotThrown(): void
    {
        $url = 'http://omeka.internal:8080/s/westafrica/item/1?page=2';
        self::assertSame($url, UrlPolicy::publicUrl($url));
        self::assertNull(UrlPolicy::originError());

        putenv('IWAC_SEO_PUBLIC_ORIGIN=https://islam.zmo.de/');
        try {
            self::assertSame('https://islam.zmo.de/s/westafrica/item/1?page=2', UrlPolicy::publicUrl($url));
            self::assertNull(UrlPolicy::originError());

            // A path makes it no origin: the page keeps rendering on Omeka's own URL.
            putenv('IWAC_SEO_PUBLIC_ORIGIN=https://islam.zmo.de/s/afrique_ouest');
            self::assertSame($url, UrlPolicy::publicUrl($url));
            self::assertNotNull(UrlPolicy::originError());
        } finally {
            putenv('IWAC_SEO_PUBLIC_ORIGIN');
        }
    }

    public function testFiltersAreNotMadeIndexableByPagination(): void
    {
        self::assertTrue(UrlPolicy::isFiltered('https://example.test/items?page=2&search=islam'));
        self::assertTrue(UrlPolicy::isFiltered('https://example.test/items?page[]=2'));
    }
}
