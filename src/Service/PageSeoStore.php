<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Omeka\Settings\SiteSettings;

/**
 * Per-site-page SEO overrides — the manual values an editor sets for static
 * pages. Stored as one JSON map under the site setting `dre_seo_pages`
 * ({pageId: {title, description, image, robots, jsonld}}), so there is no
 * custom database table and uninstall is a single delete.
 *
 * Reads in the public page listener rely on Omeka having already pointed the
 * SiteSettings service at the current site; the admin controller calls
 * setSite() explicitly before reading or writing.
 */
class PageSeoStore
{
    private const KEY = 'dre_seo_pages';

    public function __construct(private readonly SiteSettings $siteSettings)
    {
    }

    public function setSite(int $siteId): void
    {
        $this->siteSettings->setTargetId($siteId);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $value = $this->siteSettings->get(self::KEY, []);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function get(int $pageId): array
    {
        $all = $this->all();
        return isset($all[$pageId]) && is_array($all[$pageId]) ? $all[$pageId] : [];
    }

    /** @param array<string,mixed> $overrides */
    public function set(int $pageId, array $overrides): void
    {
        $all = $this->all();
        $clean = array_filter(
            $overrides,
            static fn ($v) => $v !== null && $v !== '' && $v !== '0'
        );
        if ($clean === []) {
            unset($all[$pageId]);
        } else {
            $all[$pageId] = $clean;
        }
        $this->siteSettings->set(self::KEY, $all);
    }

    /** @param array<int,array<string,mixed>> $map */
    public function replaceAll(array $map): void
    {
        $this->siteSettings->set(self::KEY, $map);
    }
}
