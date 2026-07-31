<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/**
 * Read model consumed by {@see \IwacSeo\Service\SitemapGenerator}.
 *
 * Keeping the generator on this narrow contract makes its URL-selection policy
 * testable without Doctrine or an Omeka database. The production implementation
 * remains {@see SitemapRepository}; tests can supply an in-memory repository.
 */
interface SitemapRepositoryInterface
{
    public function countItems(int $siteId): int;

    public function countItemSets(int $siteId): int;

    public function countPages(int $siteId): int;

    /**
     * @return array<array{id:int,modified:?string,storage_id?:?string}>
     */
    public function fetchItems(int $siteId, int $offset, int $limit, bool $withImages = false): array;

    /** @return array<array{id:int,modified:?string}> */
    public function fetchItemSets(int $siteId): array;

    /** @return array<array{id:int,slug:string,modified:?string}> */
    public function fetchPages(int $siteId): array;
}
