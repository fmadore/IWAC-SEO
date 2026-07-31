<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * Computes and injects every <head> SEO signal into Omeka's request-global head
 * placeholder helpers (headTitle / headMeta / headLink / headScript). The
 * theme echoes those helpers in <head>, so nothing here needs a theme edit.
 *
 * One shared instance lives for the whole request (registered shared in the
 * service manager). The Module's listeners call it in two phases:
 *
 *   1. During the action view render (view.show.after / view.browse.after) the
 *      resource- or page-specific signals are applied — these always win.
 *   2. During the layout render (view.layout, fired last) applyGlobals() adds
 *      the site-wide constants (og:site_name, verification tags, …) and
 *      gap-fills anything the first phase did not set.
 *
 * Which signals phase 1 produced is tracked by {@see HeadWriter}, which also
 * performs the writes, so phase 2 only fills gaps and never clobbers a resource
 * value. This class is the policy — what a resource, page or browse listing
 * should say — and holds no per-request state beyond a memoised default image.
 */
class HeadMetadata
{
    private const DESCRIPTION_MAX = 160;

    private ?string $defaultImageUrl = null;
    private bool $defaultImageResolved = false;

    public function __construct(
        private readonly SettingsGate $settings,
        private readonly HeadWriter $head,
        private readonly StructuredData $structuredData,
        private readonly CitationMeta $citationMeta,
        private readonly Hreflang $hreflang,
        private readonly ZoteroRdf $zoteroRdf,
    ) {
    }

    // ─── Phase 1: resource pages ────────────────────────────────────────────

    /**
     * @return string|null Optional body markup to echo after the resource view:
     *   the unAPI <abbr class="unapi-id"> element for items served as Zotero RDF,
     *   or null. Everything else here goes into the <head> placeholder helpers.
     */
    public function applyResource(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): ?string {
        $title = (string) $resource->displayTitle();
        $description = $this->resourceDescription($resource, $site);
        $canonical = ResourceUrl::forSite($resource, $site->slug());
        $image = $this->resourceImage($view, $resource);

        $this->head->openGraph($view, [
            'og:type'  => 'article',
            'og:title' => $title,
            'og:url'   => $canonical,
        ]);
        if ($description !== null) {
            $this->head->description($view, $description);
            $this->head->openGraph($view, ['og:description' => $description]);
        }
        if ($canonical !== null) {
            $this->head->canonical($view, $canonical);
        }
        if ($image !== null) {
            $this->head->image($view, $image);
        }
        $this->head->mark('og:title');

        if ($this->jsonLdEnabled()) {
            $data = $this->structuredData->forResource($resource, $site, $canonical, $image);
            if ($data !== null) {
                $this->head->jsonLd($view, $data);
            }
            $breadcrumb = $this->structuredData->breadcrumb($view, $resource, $site, $canonical);
            if ($breadcrumb !== null) {
                $this->head->jsonLd($view, $breadcrumb);
            }
        }

        // Highwire Press + Dublin Core <meta> so Zotero / Google Scholar capture
        // the page as a reference.
        if ($this->settings->isOn('iwac_seo_citation_meta', true)) {
            $this->citationMeta->apply($view, $resource, ResourceUrl::classId($resource), $canonical);
        }

        // Bilingual: link this item to its counterpart on the other-language
        // site (the same o:id, different site slug).
        $this->alternates($view, $this->hreflang->forResource($resource));

        // unAPI discovery for primary-source items. Advertise a Zotero-RDF
        // endpoint so the Zotero Connector imports the rich record (call number,
        // single-field institutional creators, tags) — unAPI outranks Embedded
        // Metadata, so for these kinds it supersedes the meta tags emitted above.
        // Returns the <abbr class="unapi-id"> element to echo in the page body.
        if (
            $canonical !== null
            && $resource instanceof ItemRepresentation
            && $this->settings->isOn('iwac_seo_unapi', true)
            && $this->zoteroRdf->isEligible(ResourceUrl::classId($resource))
        ) {
            $view->headLink([
                'rel'   => 'unapi-server',
                'type'  => 'application/xml',
                'title' => 'unAPI',
                'href'  => $view->serverUrl('/unapi'),
            ]);
            return sprintf(
                '<abbr class="unapi-id" title="%s"></abbr>',
                $view->escapeHtmlAttr($canonical)
            );
        }
        return null;
    }

    // ─── Phase 1: static site pages ─────────────────────────────────────────

    /**
     * @param array{title?:string,description?:string,image?:int|string,robots?:string} $overrides
     */
    public function applyPage(
        PhpRenderer $view,
        SitePageRepresentation $page,
        SiteRepresentation $site,
        array $overrides,
        bool $isHomepage
    ): void {
        $canonical = ResourceUrl::forSite($page, $site->slug());
        $this->head->canonical($view, $canonical);

        $title = isset($overrides['title']) && $overrides['title'] !== ''
            ? $overrides['title']
            : (string) $page->title();
        // Fully own the <title> when an editor set a custom one (clear the stack
        // first so the page's default title segment is replaced, not appended);
        // the theme appends the site + installation suffix afterwards.
        if (isset($overrides['title']) && $overrides['title'] !== '') {
            $this->head->title($view, (string) $overrides['title']);
        }

        $description = isset($overrides['description']) && $overrides['description'] !== ''
            ? $this->truncate($overrides['description'])
            : null;
        if ($description !== null) {
            $this->head->description($view, $description);
        }

        $image = null;
        if (!empty($overrides['image'])) {
            $image = $this->assetUrl($view, (int) $overrides['image']);
        }
        if ($image !== null) {
            $this->head->image($view, $image);
        }

        $this->head->openGraph($view, array_filter([
            'og:type'        => 'website',
            'og:title'       => $title,
            'og:url'         => $canonical,
            'og:description' => $description,
        ], static fn ($v) => $v !== null && $v !== ''));
        $this->head->mark('og:title');

        if (!empty($overrides['robots'])) {
            $this->head->robots($view, (string) $overrides['robots']);
        }

        if ($isHomepage && $this->jsonLdEnabled()) {
            $this->head->jsonLd($view, $this->structuredData->webSite($view, $site));
        }

        // Bilingual: link this static page to its translated counterpart on the
        // other-language site (resolved from the configured page-slug map).
        $this->alternates($view, $this->hreflang->forPage($view, $page, $site));
    }

    // ─── Phase 1: browse / search listing pages ─────────────────────────────

    public function applyBrowse(PhpRenderer $view, SiteRepresentation $site): void
    {
        // Self-referential canonical (full current URL) keeps facet/sort
        // variants from looking like duplicate content while staying safe for
        // paginated pages (no collapsing page 2 onto page 1).
        $current = $view->serverUrl(true);
        $this->head->canonical($view, $current);

        // Only noindex faceted / paginated / sorted variants (which carry a
        // query string). Clean landing pages stay indexable — crucially the
        // item-set pages (/item-set/{id}), which are listed in the sitemap;
        // marking them noindex made Search Console reject that sitemap.
        $hasQuery = ((string) (parse_url($current, PHP_URL_QUERY) ?? '')) !== '';
        if ($hasQuery && $this->settings->isOn('iwac_seo_noindex_browse')) {
            $this->head->robots($view, 'noindex, follow');
        }
    }

    // ─── Phase 2: site-wide constants + gap-fill (view.layout) ──────────────

    public function applyGlobals(PhpRenderer $view, SiteRepresentation $site): void
    {
        $headMeta = $view->headMeta();

        // Master noindex (staging) overrides everything.
        if ($this->settings->isOn('iwac_seo_noindex_site')) {
            $this->head->robots($view, 'noindex, nofollow', true);
        }

        // Verification tags — site-wide, on every public page.
        $gsc = Text::extractToken($this->settings->raw('iwac_seo_gsc_verification'));
        if ($gsc !== '') {
            $headMeta->appendName('google-site-verification', $gsc);
        }
        $bing = Text::extractToken($this->settings->raw('iwac_seo_bing_verification'));
        if ($bing !== '') {
            $headMeta->appendName('msvalidate.01', $bing);
        }

        // Open Graph / Twitter constants.
        $this->head->openGraph($view, ['og:site_name' => $site->title()]);
        // Through ogLocale() so a bare site locale ("fr") still emits the
        // language_TERRITORY form Open Graph expects, matching the
        // og:locale:alternate values below.
        $this->head->openGraph($view, ['og:locale' => $this->ogLocale(ViewLocale::resolve($view))]);
        // Advertise the other-language site(s) as og:locale:alternate.
        if ($this->hreflang->isEnabled()) {
            $currentSlug = $site->slug();
            foreach ($this->hreflang->sites() as $slug => $lang) {
                if ($slug !== $currentSlug) {
                    $this->head->appendOpenGraph(
                        $view,
                        'og:locale:alternate',
                        $this->ogLocale((string) $lang)
                    );
                }
            }
        }
        $headMeta->appendName('twitter:card', 'summary_large_image');
        $twitter = $this->settings->text('iwac_seo_twitter_site');
        if ($twitter !== '') {
            $headMeta->appendName('twitter:site', $twitter);
        }

        // Gap-fills (only when phase 1 set nothing).
        if (!$this->head->has('description')) {
            $default = $this->settings->text('iwac_seo_default_description');
            if ($default !== '') {
                $this->head->description($view, $this->truncate($default));
            }
        }
        if (!$this->head->has('image')) {
            $img = $this->resolveDefaultImage($view);
            if ($img !== null) {
                $this->head->image($view, $img);
            }
        }
        if (!$this->head->has('canonical')) {
            $this->head->canonical($view, $view->serverUrl(true));
        }
        if (!$this->head->has('og:title')) {
            // Mirror the rendered <title>'s leading segment as og/twitter title.
            $title = $this->head->leadingTitle($view) ?? $site->title();
            if ($title !== '') {
                $this->head->openGraph($view, ['og:title' => $title, 'og:type' => 'website']);
            }
        }
        // Mirror description → og/twitter description if still missing.
        $written = $this->head->writtenDescription();
        if ($written !== null && !$this->head->has('og:description')) {
            $this->head->openGraph($view, ['og:description' => $written]);
        }
        if (!$this->head->has('og:url')) {
            $this->head->openGraph($view, ['og:url' => $view->serverUrl(true)]);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resourceDescription(
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): ?string {
        // Newspaper articles carry their summary in bibo:shortDescription (the
        // AI summary); references and publication issues use dcterms:abstract;
        // authority records (persons, organisations, events) use
        // dcterms:description. Try them in that order. NOTE: this order is
        // deliberately different from ResourceValueReader::ABSTRACT_TERMS —
        // a meta description wants the punchy summary, a citation wants the
        // formal abstract. Do not unify the two.
        foreach (['bibo:shortDescription', 'dcterms:abstract', 'dcterms:description', 'bibo:abstract'] as $term) {
            $value = $resource->value($term);
            if ($value !== null) {
                $text = trim(strip_tags((string) $value));
                if ($text !== '') {
                    return $this->truncate($text);
                }
            }
        }
        // Fallback so the tag is never empty and stays unique per page: the
        // title within the collection. Built from the (already localised) site
        // title, so it reads correctly on both the French and English IWAC
        // sites without needing translation here.
        $title = (string) $resource->displayTitle();
        if ($title === '') {
            $title = ResourceUrl::classLabel($resource) ?? 'Record';
        }
        return $this->truncate(sprintf('%s — %s', $title, $site->title()));
    }

    private function resourceImage(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): ?string
    {
        $media = null;
        if ($resource instanceof ItemRepresentation) {
            $media = $resource->primaryMedia();
        } elseif ($resource instanceof MediaRepresentation) {
            $media = $resource;
        }
        if ($media instanceof MediaRepresentation) {
            $thumb = $media->thumbnailUrl('large');
            if ($thumb) {
                return $this->absolutize($view, $thumb);
            }
        }
        // Item sets and media without a thumbnail fall through to the default.
        return $this->resolveDefaultImage($view);
    }

    /**
     * Omeka file/asset URLs are already absolute (scheme + host); leave those
     * untouched and only prepend the host to a root-relative path.
     */
    private function absolutize(PhpRenderer $view, string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $view->serverUrl($url);
    }

    private function resolveDefaultImage(PhpRenderer $view): ?string
    {
        if ($this->defaultImageResolved) {
            return $this->defaultImageUrl;
        }
        $this->defaultImageResolved = true;
        $assetId = $this->settings->int('iwac_seo_default_share_image');
        if ($assetId > 0) {
            $this->defaultImageUrl = $this->assetUrl($view, $assetId);
        }
        return $this->defaultImageUrl;
    }

    private function assetUrl(PhpRenderer $view, int $assetId): ?string
    {
        if ($assetId <= 0) {
            return null;
        }
        try {
            $asset = $view->api()->read('assets', $assetId)->getContent();
            return $this->absolutize($view, $asset->assetUrl());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function truncate(string $text): string
    {
        return Text::truncate($text, self::DESCRIPTION_MAX);
    }

    /**
     * @param array<int,array{lang:string,href:string,slug:string}> $alternates
     */
    private function alternates(PhpRenderer $view, array $alternates): void
    {
        $this->head->alternates($view, $alternates, $this->hreflang->xDefaultSlug());
    }

    /**
     * Map a bare language code to an Open Graph locale (language_TERRITORY).
     * Codes that already carry a territory ("en_US", "en-GB") pass through
     * with the separator normalised.
     */
    private function ogLocale(string $lang): string
    {
        $map = ['fr' => 'fr_FR', 'en' => 'en_US'];
        if (isset($map[$lang])) {
            return $map[$lang];
        }
        return str_contains($lang, '-') ? str_replace('-', '_', $lang) : $lang;
    }

    private function jsonLdEnabled(): bool
    {
        return $this->settings->isOn('iwac_seo_jsonld_enabled', true);
    }
}
