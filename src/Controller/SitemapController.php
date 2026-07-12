<?php
declare(strict_types=1);

namespace IwacSeo\Controller;

use IwacSeo\Service\Concern\SettingsReader;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Service\SiteResolver;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * Public, anonymous endpoints served at the host root (the routes fall through
 * nginx to Omeka's index.php — no web-server config needed):
 *
 *   /sitemap.xml               sitemap index
 *   /sitemap-pages.xml         site pages (+ home)
 *   /sitemap-item-sets.xml     item-set browse pages
 *   /sitemap-items-{n}.xml     items, chunked at 50k
 *   /robots.txt                points crawlers at the sitemap
 *   /{indexnow-key}.txt        IndexNow ownership key
 */
class SitemapController extends AbstractActionController
{
    use SettingsReader;

    public function __construct(
        private readonly SitemapGenerator $generator,
        private readonly SiteResolver $siteResolver,
        private readonly Settings $settings,
        private readonly Hreflang $hreflang,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildIndex($this->hostUrl($site), $site->id(), $this->ttl()));
    }

    public function pagesAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }

        // Pass the site navigation tree + homepage so the sitemap mirrors the
        // menu structure (order + depth-based priority). Guarded so a missing
        // method or empty nav simply degrades to listing every public page.
        $navTree = [];
        $homepageId = null;
        try {
            $nav = $site->navigation();
            $navTree = is_array($nav) ? $nav : [];
            $homepage = $site->homepage();
            $homepageId = $homepage ? $homepage->id() : null;
        } catch (\Throwable $e) {
            // degrade gracefully
        }

        return $this->xml($this->generator->buildPages(
            $this->siteUrl($site),
            $site->id(),
            $this->ttl(),
            $navTree,
            $homepageId
        ));
    }

    public function itemSetsAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildItemSets(
            $this->siteUrl($site),
            $site->id(),
            $this->ttl(),
            $this->altBases($site),
            $this->xDefaultBase($site)
        ));
    }

    public function itemsAction(): Response
    {
        if (!$this->sitemapEnabled()) {
            return $this->notFound();
        }
        $site = $this->resolveSite();
        if (!$site) {
            return $this->notFound();
        }
        $chunk = (int) $this->params()->fromRoute('chunk', 1);
        if ($chunk < 1) {
            return $this->notFound();
        }
        // Chunk 1 always exists (an empty urlset is still valid), so only the
        // higher chunks pay for the bound-checking COUNT query — at IWAC's
        // scale chunk 1 is the only chunk, so cached requests stay query-free.
        if ($chunk > 1 && $chunk > $this->generator->itemChunkCount($site->id())) {
            return $this->notFound();
        }
        return $this->xml($this->generator->buildItems(
            $this->siteUrl($site),
            $site->id(),
            $chunk,
            $this->ttl(),
            $this->altBases($site),
            $this->xDefaultBase($site)
        ));
    }

    public function robotsAction(): Response
    {
        $lines = ['User-agent: *'];
        if ($this->boolSetting('iwac_seo_noindex_site')) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin/';
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /logout';
            $lines[] = 'Disallow: /maintenance';
        }

        $site = $this->resolveSite();
        if ($this->sitemapEnabled() && $site) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $this->hostUrl($site) . '/sitemap.xml';
        }

        return $this->text(implode("\n", $lines) . "\n");
    }

    public function indexNowKeyAction(): Response
    {
        $configured = trim((string) $this->settings->get('iwac_seo_indexnow_key', ''));
        $requested = (string) $this->params()->fromRoute('key', '');
        if ($configured === '' || !hash_equals($configured, $requested)) {
            return $this->notFound();
        }
        return $this->text($configured . "\n");
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveSite(): ?SiteRepresentation
    {
        return $this->siteResolver->defaultSite();
    }

    private function siteUrl(SiteRepresentation $site): string
    {
        return rtrim(
            $this->url()->fromRoute('site', ['site-slug' => $site->slug()], ['force_canonical' => true]),
            '/'
        );
    }

    private function hostUrl(SiteRepresentation $site): string
    {
        return SiteResolver::hostFromUrl($this->siteUrl($site));
    }

    /**
     * Per-language site bases for hreflang annotations in the sitemap, e.g.
     * ['lang' => 'fr', 'base' => 'https://host/s/afrique_ouest']. Each shared
     * resource has the same path under every base. Empty when hreflang is off.
     *
     * @return array<int,array{lang:string,base:string}>
     */
    private function altBases(SiteRepresentation $site): array
    {
        if (!$this->hreflang->isEnabled()) {
            return [];
        }
        $host = $this->hostUrl($site);
        $bases = [];
        foreach ($this->hreflang->sites() as $slug => $lang) {
            $bases[] = ['lang' => (string) $lang, 'base' => $host . '/s/' . $slug];
        }
        return $bases;
    }

    private function xDefaultBase(SiteRepresentation $site): ?string
    {
        if (!$this->hreflang->isEnabled()) {
            return null;
        }
        $slug = $this->hreflang->xDefaultSlug();
        return $slug !== null ? $this->hostUrl($site) . '/s/' . $slug : null;
    }

    private function ttl(): int
    {
        return (int) $this->settings->get('iwac_seo_sitemap_ttl', 86400);
    }

    private function sitemapEnabled(): bool
    {
        return $this->boolSetting('iwac_seo_sitemap_enabled', true);
    }

    private function xml(string $body): Response
    {
        $response = $this->getResponse();
        $response->setContent($body);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/xml; charset=utf-8');
        $headers->addHeaderLine('X-Robots-Tag', 'noindex'); // don't index the sitemap file itself

        // The XML is already file-cached server-side; let crawlers and any
        // CDN revalidate instead of refetching for the same window.
        $ttl = $this->ttl();
        if ($ttl > 0) {
            $headers->addHeaderLine('Cache-Control', 'public, max-age=' . $ttl);
        }
        $lastModified = $this->generator->lastModified();
        if ($lastModified !== null) {
            $headers->addHeaderLine('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
        return $response;
    }

    private function text(string $body): Response
    {
        $response = $this->getResponse();
        $response->setContent($body);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        return $response;
    }

    private function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);
        return $response;
    }
}
