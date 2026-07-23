<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * Resolves the instance's default site — the site the host-root endpoints
 * (sitemap, robots, IndexNow) and the canonical citation URLs are built
 * against: the configured `default_site`, falling back to the first site.
 *
 * One shared instance per request with a resolved-once cache, replacing the
 * four copies of this lookup that used to live in Module, SitemapController,
 * CitationController and SeoController.
 */
class SiteResolver
{
    private ?SiteRepresentation $site = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function defaultSite(): ?SiteRepresentation
    {
        if ($this->resolved) {
            return $this->site;
        }
        $this->resolved = true;

        $defaultSiteId = (int) $this->settings->get('default_site');
        if ($defaultSiteId) {
            try {
                $this->site = $this->api->read('sites', $defaultSiteId)->getContent();
                return $this->site;
            } catch (\Throwable $e) {
                // fall through to first site
            }
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
            $this->site = $sites[0] ?? null;
        } catch (\Throwable $e) {
            $this->site = null;
        }
        return $this->site;
    }

    public function defaultSlug(): ?string
    {
        $site = $this->defaultSite();
        return $site ? $site->slug() : null;
    }

    /**
     * The scheme://host[:port] part of an already-built canonical URL —
     * where the host-root endpoints (/sitemap.xml, /robots.txt, /{key}.txt)
     * live. Pure string surgery so it works with any URL the router built.
     */
    public static function hostFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }
}
