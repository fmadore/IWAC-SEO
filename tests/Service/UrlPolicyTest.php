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

    public function testFiltersAreNotMadeIndexableByPagination(): void
    {
        self::assertTrue(UrlPolicy::isFiltered('https://example.test/items?page=2&search=islam'));
        self::assertTrue(UrlPolicy::isFiltered('https://example.test/items?page[]=2'));
    }
}
