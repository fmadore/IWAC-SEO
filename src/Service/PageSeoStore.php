<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Settings\SiteSettings;

/**
 * Per-site-page SEO overrides — the manual values an editor sets for static
 * pages. Stored as one JSON map under the site setting `iwac_seo_pages`
 * ({pageId: {title, description, image, robots}}), so there is no custom
 * database table and uninstall is a single delete.
 *
 * Reads in the public page listener rely on Omeka having already pointed the
 * SiteSettings service at the current site; the admin controller calls
 * setSite() explicitly before reading or writing.
 */
class PageSeoStore
{
    private const KEY = 'iwac_seo_pages';

    /**
     * The decoded map, memoised per target site. get() is called once per page
     * render and all() once per admin table row, and each call otherwise re-read
     * and re-decoded the whole JSON blob.
     *
     * @var array<int,array<string,mixed>>|null
     */
    private ?array $cached = null;

    /** The site id $cached belongs to, so a switch invalidates it. */
    private ?int $cachedSiteId = null;

    public function __construct(private readonly SiteSettings $siteSettings)
    {
    }

    public function setSite(int $siteId): void
    {
        if ($siteId !== $this->cachedSiteId) {
            $this->cached = null;
        }
        $this->cachedSiteId = $siteId;
        $this->siteSettings->setTargetId($siteId);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $value = $this->siteSettings->get(self::KEY, []);
        return $this->cached = is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function get(int $pageId): array
    {
        $all = $this->all();
        return isset($all[$pageId]) && is_array($all[$pageId]) ? $all[$pageId] : [];
    }

    /** @param array<int,array<string,mixed>> $map */
    public function replaceAll(array $map): void
    {
        $this->siteSettings->set(self::KEY, $map);
        $this->cached = $map;
    }
}
