<?php
declare(strict_types=1);

namespace IwacSeo\Test\Double;

use IwacSeo\Service\Sitemap\SitemapRepositoryInterface;

/** A deterministic sitemap read model for policy-level generator tests. */
final class InMemorySitemapRepository implements SitemapRepositoryInterface
{
    /** @var array<array{id:int,modified:?string,storage_id?:?string}> */
    public array $items = [];

    /** @var array<array{id:int,modified:?string}> */
    public array $itemSets = [];

    /** @var array<array{id:int,slug:string,modified:?string}> */
    public array $pages = [];

    /** Override for testing index chunking without manufacturing thousands of rows. */
    public ?int $itemCount = null;

    /** @var array{siteId:int,offset:int,limit:int,withImages:bool}|null */
    public ?array $lastItemFetch = null;

    public function countItems(int $siteId): int
    {
        return $this->itemCount ?? count($this->items);
    }

    public function countItemSets(int $siteId): int
    {
        return count($this->itemSets);
    }

    public function countPages(int $siteId): int
    {
        return count($this->pages);
    }

    public function fetchItems(int $siteId, int $offset, int $limit, bool $withImages = false): array
    {
        $this->lastItemFetch = [
            'siteId'     => $siteId,
            'offset'     => $offset,
            'limit'      => $limit,
            'withImages' => $withImages,
        ];
        return array_slice($this->items, $offset, $limit);
    }

    public function fetchItemSets(int $siteId): array
    {
        return $this->itemSets;
    }

    public function fetchPages(int $siteId): array
    {
        return $this->pages;
    }
}
