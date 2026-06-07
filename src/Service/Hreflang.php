<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * Resolves cross-language alternates for the bilingual IWAC archive so the
 * module can emit reciprocal <link rel="alternate" hreflang> tags (and matching
 * <xhtml:link> entries in the sitemap). Canonicals stay self-referential per
 * language; this only adds the alternate links that tie the two together.
 *
 * IWAC publishes the same collection as two Omeka sites:
 *   - afrique_ouest → fr  (Collection Islam Afrique de l'Ouest, CIAO)
 *   - westafrica    → en  (Islam West Africa Collection, IWAC)
 *
 * - **Resources** (items / item sets / media) are shared across both sites under
 *   the same o:id, so an alternate is just the resource URL on the other site
 *   slug — a free slug-swap, no lookup needed.
 * - **Static pages** are NOT shared: each site has its own slugs (accueil/home,
 *   a-propos/about …). Equivalents come from the configured `page_pairs` map; a
 *   page with no entry simply gets no page-level alternate (never a broken one).
 *
 * Pure config + URL building — no API or database access, so it is cheap to call
 * on every request. All keys live under iwac_seo.hreflang and are overridable.
 */
class Hreflang
{
    private bool $enabled;
    /** @var array<string,string> site slug => hreflang lang code, in order */
    private array $sites;
    private ?string $xDefaultSlug;
    /** @var array<int,array<string,string>> each row: [siteSlug => pageSlug] */
    private array $pagePairs;

    /** @param array<string,mixed> $config the iwac_seo.hreflang block */
    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $sites = $config['sites'] ?? [];
        $this->sites = is_array($sites) ? $sites : [];
        $this->xDefaultSlug = isset($config['x_default']) ? (string) $config['x_default'] : null;
        $pairs = $config['page_pairs'] ?? [];
        $this->pagePairs = is_array($pairs) ? $pairs : [];
    }

    /** Only meaningful with at least two configured sites. */
    public function isEnabled(): bool
    {
        return $this->enabled && count($this->sites) > 1;
    }

    /** @return array<string,string> slug => lang, in declaration order */
    public function sites(): array
    {
        return $this->sites;
    }

    public function xDefaultSlug(): ?string
    {
        return $this->xDefaultSlug;
    }

    /**
     * Alternates for a shared resource: the same resource on every configured
     * site (the URL differs only by the site slug).
     *
     * @return array<int,array{lang:string,href:string,slug:string}>
     */
    public function forResource(AbstractResourceEntityRepresentation $resource): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $out = [];
        foreach ($this->sites as $slug => $lang) {
            try {
                $href = $resource->siteUrl($slug, true);
            } catch (\Throwable $e) {
                continue;
            }
            if ($href) {
                $out[] = ['lang' => (string) $lang, 'href' => $href, 'slug' => (string) $slug];
            }
        }
        return $out;
    }

    /**
     * Alternates for a static page: look up the equivalent page slug on each
     * site via `page_pairs`, then build its canonical URL. A page absent from
     * the map yields no alternates (so we never link a guessed/missing slug).
     *
     * @return array<int,array{lang:string,href:string,slug:string}>
     */
    public function forPage(
        PhpRenderer $view,
        SitePageRepresentation $page,
        SiteRepresentation $currentSite
    ): array {
        if (!$this->isEnabled()) {
            return [];
        }
        $currentSlug = $currentSite->slug();
        $pair = $this->pairFor($currentSlug, $page->slug());
        if ($pair === null) {
            return [];
        }

        $out = [];
        foreach ($this->sites as $slug => $lang) {
            $pageSlug = $pair[$slug] ?? null;
            if ($pageSlug === null) {
                continue; // pair does not cover this site
            }
            try {
                $href = $view->url(
                    'site/page',
                    ['site-slug' => $slug, 'page-slug' => $pageSlug],
                    ['force_canonical' => true]
                );
            } catch (\Throwable $e) {
                continue;
            }
            $out[] = ['lang' => (string) $lang, 'href' => $href, 'slug' => (string) $slug];
        }
        return $out;
    }

    /**
     * The page_pairs row that maps the current site's slug to $pageSlug, if any.
     *
     * @return array<string,string>|null
     */
    private function pairFor(string $siteSlug, string $pageSlug): ?array
    {
        foreach ($this->pagePairs as $pair) {
            if (is_array($pair) && ($pair[$siteSlug] ?? null) === $pageSlug) {
                return $pair;
            }
        }
        return null;
    }
}
