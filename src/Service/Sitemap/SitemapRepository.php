<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

use Doctrine\DBAL\Connection;

/**
 * The sitemap's data access: public resource ids and modified timestamps for
 * one site, read with one lean DBAL query per type.
 *
 * Deliberately not the ORM or the API layer — hydrating ~9k item
 * representations to emit ~9k `<loc>` elements would cost seconds and megabytes
 * for two columns per row. Every query is scoped to the site and to public
 * resources, and every one degrades to an empty result rather than throwing:
 * a sitemap that is missing a section is recoverable, a 500 on /sitemap.xml is
 * not.
 */
final class SitemapRepository
{
    private const ITEM = 'Omeka\Entity\Item';
    private const ITEM_SET = 'Omeka\Entity\ItemSet';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function countItems(int $siteId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) FROM resource r
             JOIN item_site isi ON isi.item_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s',
            ['t' => self::ITEM, 's' => $siteId]
        );
    }

    public function countItemSets(int $siteId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) FROM resource r
             JOIN site_item_set sis ON sis.item_set_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s',
            ['t' => self::ITEM_SET, 's' => $siteId]
        );
    }

    /** Public site pages, home included. */
    public function countPages(int $siteId): int
    {
        return $this->countScalar(
            'SELECT COUNT(*) FROM site_page WHERE site_id = :s AND is_public = 1',
            ['s' => $siteId]
        );
    }

    /**
     * @return array<array{id:int,modified:?string,storage_id?:?string}>
     */
    public function fetchItems(int $siteId, int $offset, int $limit, bool $withImages = false): array
    {
        // LIMIT/OFFSET are inlined as already-cast integers: PDO refuses bound
        // parameters there under emulated prepares, and casting makes it safe.
        $limit = max(0, $limit);
        $offset = max(0, $offset);

        // With images: the storage id of the item's representative thumbnail —
        // the primary media when set (and public, with thumbnails), else the
        // first public media with thumbnails, in position order. Mirrors how
        // primaryMedia() picks the og:image source.
        $imageColumn = $withImages
            ? ', (SELECT m.storage_id FROM media m
                  JOIN resource rm ON rm.id = m.id
                  WHERE m.item_id = r.id AND m.has_thumbnails = 1 AND rm.is_public = 1
                  ORDER BY COALESCE(m.id = i.primary_media_id, 0) DESC, m.position ASC, m.id ASC
                  LIMIT 1) AS storage_id'
            : '';
        $itemJoin = $withImages ? ' JOIN item i ON i.id = r.id' : '';

        try {
            return $this->connection->fetchAllAssociative(
                'SELECT r.id, r.modified' . $imageColumn . ' FROM resource r'
                . $itemJoin
                . ' JOIN item_site isi ON isi.item_id = r.id
                 WHERE r.resource_type = :t AND r.is_public = 1 AND isi.site_id = :s
                 ORDER BY r.id LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['t' => self::ITEM, 's' => $siteId]
            );
        } catch (\Throwable $e) {
            // A failing image subquery (schema drift) must not empty the
            // sitemap — retry lean before giving up.
            return $withImages ? $this->fetchItems($siteId, $offset, $limit, false) : [];
        }
    }

    /** @return array<array{id:int,modified:?string}> */
    public function fetchItemSets(int $siteId): array
    {
        return $this->fetchAll(
            'SELECT r.id, r.modified FROM resource r
             JOIN site_item_set sis ON sis.item_set_id = r.id
             WHERE r.resource_type = :t AND r.is_public = 1 AND sis.site_id = :s
             ORDER BY r.id',
            ['t' => self::ITEM_SET, 's' => $siteId]
        );
    }

    /** @return array<array{id:int,slug:string,modified:?string}> */
    public function fetchPages(int $siteId): array
    {
        return $this->fetchAll(
            'SELECT id, slug, modified FROM site_page
             WHERE site_id = :s AND is_public = 1 ORDER BY id',
            ['s' => $siteId]
        );
    }

    /** @param array<string,mixed> $params */
    private function countScalar(string $sql, array $params): int
    {
        try {
            return (int) $this->connection->fetchOne($sql, $params);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<array<string,mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        try {
            return $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
