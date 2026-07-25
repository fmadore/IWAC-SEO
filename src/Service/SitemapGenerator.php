<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Sitemap\SitemapDocument;
use IwacSeo\Service\Sitemap\SitemapRepository;
use IwacSeo\Service\Sitemap\UrlsetWriter;
use IwacSeo\Service\Sitemap\XmlCache;

/**
 * Builds the sitemap index and the per-type child sitemaps for one site.
 *
 * This class is now only the *policy*: which URLs belong in which document, in
 * what order, at which priority. The three mechanisms it used to carry inline
 * live beside it — {@see SitemapRepository} (the lean DBAL queries),
 * {@see UrlsetWriter} (the XML) and {@see XmlCache} (the TTL file cache) — so
 * the part that produces the actual protocol output is pure and testable.
 *
 * URL construction is intentionally string-based (the caller passes the
 * already-canonical host and site roots) rather than invoking the URL helper
 * thousands of times.
 */
class SitemapGenerator
{
    /** Sitemaps protocol hard cap is 50,000 URLs / 50 MB per file. */
    private const MAX_URLS_PER_FILE = 50000;

    /**
     * @param array<string,mixed> $config the 'iwac_seo.sitemap' config block
     * @param string|null $fileBaseUri the file store's public base URI
     *   (config file_store.local.base_uri), or null to derive it from the
     *   site URL at build time ({site base}/files)
     */
    public function __construct(
        private readonly SitemapRepository $repository,
        private readonly UrlsetWriter $writer,
        private readonly XmlCache $cache,
        private readonly array $config,
        private readonly ?string $fileBaseUri = null,
    ) {
    }

    private function chunkSize(): int
    {
        $size = (int) ($this->config['item_chunk_size'] ?? self::MAX_URLS_PER_FILE);
        return ($size > 0 && $size <= self::MAX_URLS_PER_FILE) ? $size : self::MAX_URLS_PER_FILE;
    }

    // ─── Index ──────────────────────────────────────────────────────────────

    public function buildIndex(string $hostUrl, int $siteId, int $ttl): SitemapDocument
    {
        return $this->cache->remember($this->key($siteId, 'index'), $ttl, function () use ($hostUrl, $siteId) {
            $children = ['sitemap-pages.xml', 'sitemap-item-sets.xml'];
            for ($i = 1, $n = $this->itemChunkCount($siteId); $i <= $n; $i++) {
                $children[] = 'sitemap-items-' . $i . '.xml';
            }
            return $this->writer->renderIndex(
                array_map(static fn (string $child): string => $hostUrl . '/' . $child, $children),
                UrlsetWriter::now()
            );
        });
    }

    // ─── Child sitemaps ───────────────────────────────────────────────────

    /**
     * Pages sitemap, driven by the site navigation so it mirrors the real site
     * structure: home first, then menu pages in order with a priority that
     * reflects their menu depth (top-level entries outrank submenu items), then
     * any remaining public pages (demoted, so nothing is silently dropped).
     *
     * @param array<mixed> $navTree the site's o:navigation tree
     */
    public function buildPages(
        string $siteUrl,
        int $siteId,
        int $ttl,
        array $navTree = [],
        ?int $homepageId = null
    ): SitemapDocument {
        return $this->cache->remember(
            $this->key($siteId, 'pages'),
            $ttl,
            function () use ($siteUrl, $siteId, $navTree, $homepageId): string {
                // All public pages, keyed by id.
                $pagesById = [];
                foreach ($this->repository->fetchPages($siteId) as $row) {
                    $pagesById[(int) $row['id']] = $row;
                }

                $urls = [];
                $emitted = [];
                $pageUrl = fn (array $row, ?string $priority, ?string $changefreq): array => [
                    'loc'        => $siteUrl . '/page/' . rawurlencode((string) $row['slug']),
                    'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                    'changefreq' => $changefreq,
                    'priority'   => $priority,
                ];

                // Home first, at its canonical /page/{slug}. The bare site root
                // only redirects there, so listing the page URL avoids both a
                // redirect and a duplicate entry. Falls back to the root if the
                // homepage is unknown.
                if ($homepageId !== null && isset($pagesById[$homepageId])) {
                    $urls[] = $pageUrl($pagesById[$homepageId], $this->priority('home'), $this->changefreq('home'));
                    $emitted[$homepageId] = true;
                } else {
                    $urls[] = [
                        'loc'        => $siteUrl . '/',
                        'changefreq' => $this->changefreq('home'),
                        'priority'   => $this->priority('home'),
                    ];
                }

                // Navigation pages, in menu order; priority by depth.
                foreach ($this->flattenNav($navTree) as $nav) {
                    $id = $nav['id'];
                    if (isset($emitted[$id]) || !isset($pagesById[$id])) {
                        continue;
                    }
                    $priority = $nav['depth'] === 0 ? $this->priority('section') : $this->priority('page');
                    $urls[] = $pageUrl($pagesById[$id], $priority, $this->changefreq('page'));
                    $emitted[$id] = true;
                }

                // Public pages not reachable from the navigation (e.g. a search
                // page) — kept for coverage but demoted.
                foreach ($pagesById as $id => $row) {
                    if (!isset($emitted[$id])) {
                        $urls[] = $pageUrl($row, $this->priority('browse'), $this->changefreq('page'));
                    }
                }

                return $this->writer->renderUrlset($urls);
            }
        );
    }

    /**
     * @param array<int,array{lang:string,base:string}> $altBases per-language site bases for hreflang
     */
    public function buildItemSets(
        string $siteUrl,
        int $siteId,
        int $ttl,
        array $altBases = [],
        ?string $xDefaultBase = null
    ): SitemapDocument {
        return $this->cache->remember(
            $this->key($siteId, 'item-sets'),
            $ttl,
            function () use ($siteUrl, $siteId, $altBases, $xDefaultBase): string {
                $urls = [];
                foreach ($this->repository->fetchItemSets($siteId) as $row) {
                    $path = '/item-set/' . (int) $row['id'];
                    $urls[] = [
                        'loc'        => $siteUrl . $path,
                        'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                        'changefreq' => $this->changefreq('browse'),
                        'priority'   => $this->priority('section'),
                        'alternates' => $this->altLinks($altBases, $xDefaultBase, $path),
                    ];
                }
                return $this->writer->renderUrlset($urls);
            }
        );
    }

    /**
     * @param array<int,array{lang:string,base:string}> $altBases per-language site bases for hreflang
     */
    public function buildItems(
        string $siteUrl,
        int $siteId,
        int $chunk,
        int $ttl,
        array $altBases = [],
        ?string $xDefaultBase = null
    ): SitemapDocument {
        $chunk = max(1, $chunk);
        return $this->cache->remember(
            $this->key($siteId, 'items-' . $chunk),
            $ttl,
            function () use ($siteUrl, $siteId, $chunk, $altBases, $xDefaultBase): string {
                $size = $this->chunkSize();
                $withImages = (bool) ($this->config['include_images'] ?? true);
                $imageBase = $withImages ? $this->imageBase($siteUrl) : null;

                $urls = [];
                foreach ($this->repository->fetchItems($siteId, ($chunk - 1) * $size, $size, $withImages) as $row) {
                    $path = '/item/' . (int) $row['id'];
                    $url = [
                        'loc'        => $siteUrl . $path,
                        'lastmod'    => UrlsetWriter::w3cDate($row['modified'] ?? null),
                        'changefreq' => $this->changefreq('item'),
                        'priority'   => $this->priority('item'),
                        'alternates' => $this->altLinks($altBases, $xDefaultBase, $path),
                    ];
                    // Google Images: the item's primary-media large thumbnail
                    // (the page scan / cover) as an <image:image> entry.
                    if ($imageBase !== null && !empty($row['storage_id'])) {
                        $url['image'] = $imageBase . '/large/' . $row['storage_id'] . '.jpg';
                    }
                    $urls[] = $url;
                }
                return $this->writer->renderUrlset($urls);
            }
        );
    }

    public function itemChunkCount(int $siteId): int
    {
        return max(1, (int) ceil($this->repository->countItems($siteId) / $this->chunkSize()));
    }

    /** @return array{items:int,itemSets:int,pages:int} */
    public function counts(int $siteId): array
    {
        return [
            'items'    => $this->repository->countItems($siteId),
            'itemSets' => $this->repository->countItemSets($siteId),
            'pages'    => $this->repository->countPages($siteId),
        ];
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    /** Clear the cache and remove the cache directory itself (uninstall). */
    public function destroyCache(): void
    {
        $this->cache->destroy();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Flattens the Omeka navigation tree into an ordered list of page links with
     * their menu depth. Only `page`-type links are followed; url / browse links
     * are not emitted as canonical pages here.
     *
     * @param array<mixed> $tree
     * @param array<array{id:int,depth:int}> $acc
     * @return array<array{id:int,depth:int}>
     */
    private function flattenNav(array $tree, int $depth = 0, array &$acc = []): array
    {
        foreach ($tree as $link) {
            if (!is_array($link)) {
                continue;
            }
            if (($link['type'] ?? null) === 'page' && isset($link['data']['id'])) {
                $acc[] = ['id' => (int) $link['data']['id'], 'depth' => $depth];
            }
            if (!empty($link['links']) && is_array($link['links'])) {
                $this->flattenNav($link['links'], $depth + 1, $acc);
            }
        }
        return $acc;
    }

    /**
     * The public base URI of stored files: the configured file_store base_uri,
     * else "{site base}/files" derived from the site URL (works for root and
     * sub-directory installs alike, since the /s/{slug} suffix is stripped).
     */
    private function imageBase(string $siteUrl): string
    {
        if ($this->fileBaseUri !== null && $this->fileBaseUri !== '') {
            return rtrim($this->fileBaseUri, '/');
        }
        return preg_replace('#/s/[^/]+$#', '', $siteUrl) . '/files';
    }

    /**
     * Build the per-URL hreflang alternate set for a resource path. Each shared
     * resource lives at the same path under every site slug, so the alternates
     * are just that path appended to each language base (+ x-default).
     *
     * @param array<int,array{lang:string,base:string}> $altBases
     * @return array<int,array{hreflang:string,href:string}>
     */
    private function altLinks(array $altBases, ?string $xDefaultBase, string $path): array
    {
        if (count($altBases) < 2) {
            return [];
        }
        $links = [];
        foreach ($altBases as $alt) {
            $links[] = ['hreflang' => $alt['lang'], 'href' => $alt['base'] . $path];
        }
        if ($xDefaultBase !== null) {
            $links[] = ['hreflang' => 'x-default', 'href' => $xDefaultBase . $path];
        }
        return $links;
    }

    /**
     * Cache key, namespaced by site id. Without the site id, changing
     * `default_site` served the previous site's XML until the TTL expired.
     */
    private function key(int $siteId, string $type): string
    {
        return 'site-' . $siteId . '-' . $type;
    }

    private function changefreq(string $kind): ?string
    {
        return $this->config['changefreq'][$kind] ?? null;
    }

    private function priority(string $kind): ?string
    {
        return $this->config['priority'][$kind] ?? null;
    }
}
