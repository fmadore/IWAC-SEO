<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\HttpCache;
use PHPUnit\Framework\TestCase;

/** Cache validators for the citation downloads, and RFC 9110's precedence between them. */
final class HttpCacheTest extends TestCase
{
    public function testTheETagIsStrongAndFollowsTheBody(): void
    {
        $etag = HttpCache::etag('body');

        $this->assertMatchesRegularExpression('/\A"[0-9a-f]{32}"\z/', $etag);
        $this->assertSame($etag, HttpCache::etag('body'));
        $this->assertNotSame($etag, HttpCache::etag('body '));
    }

    public function testLastModifiedIsAnHttpDateInGmt(): void
    {
        $modified = new \DateTimeImmutable('2026-10-01 14:00:00', new \DateTimeZone('Europe/Berlin'));

        $this->assertSame('Thu, 01 Oct 2026 12:00:00 GMT', HttpCache::lastModified($modified));
        $this->assertNull(HttpCache::lastModified(null));
    }

    public function testIfNoneMatchMatchesAnyListedTagWeaklyOrAStar(): void
    {
        $etag = HttpCache::etag('body');
        $modified = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');

        $this->assertTrue(HttpCache::notModified($etag, $modified, $etag, null));
        $this->assertTrue(HttpCache::notModified($etag, $modified, '"other", W/' . $etag, null));
        $this->assertTrue(HttpCache::notModified($etag, $modified, '*', null));
        $this->assertFalse(HttpCache::notModified($etag, $modified, '"other"', null));
    }

    public function testIfNoneMatchOutranksIfModifiedSince(): void
    {
        $etag = HttpCache::etag('body');
        $modified = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');

        $this->assertFalse(HttpCache::notModified($etag, $modified, '"other"', 'Thu, 01 Oct 2026 13:00:00 GMT'));
    }

    public function testIfModifiedSinceComparesWithTheRecordsLastChange(): void
    {
        $etag = HttpCache::etag('body');
        $modified = new \DateTimeImmutable('2026-10-01 12:00:00 UTC');

        $this->assertTrue(HttpCache::notModified($etag, $modified, null, 'Thu, 01 Oct 2026 12:00:00 GMT'));
        $this->assertFalse(HttpCache::notModified($etag, $modified, null, 'Thu, 01 Oct 2026 11:59:59 GMT'));
        $this->assertFalse(HttpCache::notModified($etag, null, null, 'Thu, 01 Oct 2026 12:00:00 GMT'), 'no date, no comparison');
        $this->assertFalse(HttpCache::notModified($etag, $modified, null, 'not a date'));
        $this->assertFalse(HttpCache::notModified($etag, $modified, null, null));
    }
}
