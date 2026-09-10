<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use IwacSeo\Service\PageSeoStore;
use Omeka\Mvc\Status;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

final class PageSeoStoreTest extends TestCase
{
    public function testNoindexHomepageIsNotReintroducedAtTheSiteRoot(): void
    {
        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturn([1 => ['robots' => 'noindex, follow']]);
        $store = new PageSeoStore($settings);
        $repository = new \IwacSeo\Test\Double\InMemorySitemapRepository();
        $repository->pages = [['id' => 1, 'slug' => 'home', 'modified' => null],
            ['id' => 2, 'slug' => 'about', 'modified' => null]];
        $generator = new \IwacSeo\Service\SitemapGenerator(
            $repository,
            new \IwacSeo\Service\Sitemap\UrlsetWriter(),
            new \IwacSeo\Service\Sitemap\XmlCache(null),
            [],
            null,
            $store
        );
        $xml = $generator->buildPages('https://example.test/s/en', 1, 0, [], 1)->xml;
        self::assertStringContainsString('/page/about', $xml);
        self::assertStringNotContainsString('/page/home', $xml);
        self::assertStringNotContainsString('<loc>https://example.test/s/en/</loc>', $xml);
    }

    public function testStaleEditorCannotOverwriteAConcurrentSave(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE site (id INTEGER PRIMARY KEY)');
        $db->executeStatement('INSERT INTO site VALUES (1)');
        $db->executeStatement('CREATE TABLE site_setting (site_id INTEGER, id TEXT, value TEXT, PRIMARY KEY(site_id, id))');
        $status = $this->createMock(Status::class);
        $status->method('isInstalled')->willReturn(true);
        $first = new PageSeoStore(new SiteSettings($db, $status), $db);
        $second = new PageSeoStore(new SiteSettings($db, $status), $db);
        $first->setSite(1);
        $second->setSite(1);
        $firstRevision = $first->revision();
        $secondRevision = $second->revision();
        self::assertTrue($first->save([5 => ['title' => 'First editor']], $firstRevision));
        self::assertFalse($second->save([5 => ['title' => 'Second editor']], $secondRevision));
        $fresh = new PageSeoStore(new SiteSettings($db, $status), $db);
        $fresh->setSite(1);
        self::assertSame('First editor', $fresh->get(5)['title']);
    }
}
