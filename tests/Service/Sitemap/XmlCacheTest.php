<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service\Sitemap;

use IwacSeo\Service\Sitemap\XmlCache;
use PHPUnit\Framework\TestCase;

/**
 * The TTL file cache. Caching a sitemap is an optimisation, never a
 * correctness requirement, so the failure modes matter as much as the hits.
 */
final class XmlCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iwac-seo-cache-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testFirstCallBuildsAndSecondCallHitsTheCache(): void
    {
        $cache = new XmlCache($this->dir);
        $builds = 0;
        $build = function () use (&$builds): string {
            $builds++;
            return '<xml>' . $builds . '</xml>';
        };

        $first = $cache->remember('site-1-pages', 3600, $build);
        $second = $cache->remember('site-1-pages', 3600, $build);

        $this->assertSame('<xml>1</xml>', $first->xml);
        $this->assertSame('<xml>1</xml>', $second->xml, 'served from cache');
        $this->assertSame(1, $builds);
    }

    public function testKeysAreIndependent(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('site-1-pages', 3600, static fn (): string => 'one');
        $cache->remember('site-2-pages', 3600, static fn (): string => 'two');

        $this->assertSame('one', $cache->remember('site-1-pages', 3600, static fn (): string => 'rebuilt')->xml);
        $this->assertSame('two', $cache->remember('site-2-pages', 3600, static fn (): string => 'rebuilt')->xml);
    }

    public function testExpiredEntriesAreRebuilt(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('k', 3600, static fn (): string => 'stale');
        touch($this->dir . '/k.xml', time() - 7200);

        $this->assertSame('fresh', $cache->remember('k', 3600, static fn (): string => 'fresh')->xml);
    }

    public function testZeroTtlDisablesCachingEntirely(): void
    {
        $cache = new XmlCache($this->dir);
        $document = $cache->remember('k', 0, static fn (): string => 'live');

        $this->assertSame('live', $document->xml);
        $this->assertFileDoesNotExist($this->dir . '/k.xml');
    }

    public function testNoDirectoryStillServesLiveDocuments(): void
    {
        $cache = new XmlCache(null);
        $this->assertSame('live', $cache->remember('k', 3600, static fn (): string => 'live')->xml);
    }

    public function testLastModifiedIsTheCacheMtimeOnAHit(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('k', 3600, static fn (): string => 'cached');

        $written = time() - 120;
        touch($this->dir . '/k.xml', $written);

        $document = $cache->remember('k', 3600, static fn (): string => 'rebuilt');
        $this->assertSame('cached', $document->xml);
        $this->assertSame($written, $document->lastModified);
        $this->assertStringEndsWith('GMT', $document->lastModifiedHeader());
    }

    public function testClearRemovesEveryDocument(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->remember('b', 3600, static fn (): string => 'b');

        $cache->clear();

        $this->assertSame([], glob($this->dir . '/*.xml'));
    }

    /**
     * The API listener clears on every content change, so a bulk import would
     * otherwise run one glob-and-unlink cycle per saved item.
     */
    public function testClearIsDebouncedWithinARequest(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->clear();

        // Simulate a document reappearing without going through the cache.
        file_put_contents($this->dir . '/a.xml', 'stale');
        $cache->clear();
        $this->assertFileExists($this->dir . '/a.xml', 'second clear is a no-op');

        // Writing through the cache re-arms the debounce.
        $cache->remember('b', 3600, static fn (): string => 'b');
        $cache->clear();
        $this->assertSame([], glob($this->dir . '/*.xml'));
    }

    public function testDestroyAlwaysSweepsAndRemovesTheDirectory(): void
    {
        $cache = new XmlCache($this->dir);
        $cache->remember('a', 3600, static fn (): string => 'a');
        $cache->clear();          // arms the debounce
        file_put_contents($this->dir . '/a.xml', 'stale');

        $cache->destroy();        // uninstall must sweep regardless

        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
