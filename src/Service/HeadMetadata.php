<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Concern\SettingsReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

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
 * The $applied set records which signals phase 1 produced so phase 2 only fills
 * gaps and never clobbers a resource value.
 */
class HeadMetadata
{
    use SettingsReader;

    private const DESCRIPTION_MAX = 160;

    /** @var array<string,bool> Signals already emitted this request. */
    private array $applied = [];

    private ?string $description = null;
    private ?string $defaultImageUrl = null;
    private bool $defaultImageResolved = false;

    public function __construct(
        private readonly Settings $settings,
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
        $canonical = $this->absoluteResourceUrl($resource, $site);
        $image = $this->resourceImage($view, $resource);

        $this->setOpenGraph($view, [
            'og:type'  => 'article',
            'og:title' => $title,
            'og:url'   => $canonical,
        ]);
        if ($description !== null) {
            $this->setDescription($view, $description);
            $this->setOpenGraph($view, ['og:description' => $description]);
        }
        if ($canonical !== null) {
            $this->setCanonical($view, $canonical);
        }
        if ($image !== null) {
            $this->setImage($view, $image);
        }
        $this->markApplied('og:title');

        if ($this->jsonLdEnabled()) {
            $data = $this->structuredData->forResource($resource, $site, $canonical, $image);
            if ($data !== null) {
                $this->addJsonLd($view, $data);
            }
            $breadcrumb = $this->structuredData->breadcrumb($view, $resource, $site, $canonical);
            if ($breadcrumb !== null) {
                $this->addJsonLd($view, $breadcrumb);
            }
        }

        // Highwire Press + Dublin Core <meta> so Zotero / Google Scholar capture
        // the page as a reference.
        if ($this->boolSetting('iwac_seo_citation_meta', true)) {
            $classId = $resource->resourceClass() ? $resource->resourceClass()->id() : null;
            $this->citationMeta->apply($view, $resource, $classId, $canonical);
        }

        // Bilingual: link this item to its counterpart on the other-language
        // site (the same o:id, different site slug).
        $this->emitAlternates($view, $this->hreflang->forResource($resource));

        // unAPI discovery for primary-source items. Advertise a Zotero-RDF
        // endpoint so the Zotero Connector imports the rich record (call number,
        // single-field institutional creators, tags) — unAPI outranks Embedded
        // Metadata, so for these kinds it supersedes the meta tags emitted above.
        // Returns the <abbr class="unapi-id"> element to echo in the page body.
        if ($canonical !== null
            && $resource instanceof ItemRepresentation
            && $this->boolSetting('iwac_seo_unapi', true)
            && $this->zoteroRdf->isEligible($resource->resourceClass() ? $resource->resourceClass()->id() : null)
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
        $canonical = $page->siteUrl($site->slug(), true);
        $this->setCanonical($view, $canonical);

        $title = isset($overrides['title']) && $overrides['title'] !== ''
            ? $overrides['title']
            : (string) $page->title();
        // Fully own the <title> when an editor set a custom one (clear the stack
        // first so the page's default title segment is replaced, not appended);
        // the theme appends the site + installation suffix afterwards.
        if (isset($overrides['title']) && $overrides['title'] !== '') {
            $view->headTitle()->getContainer()->exchangeArray([]);
            $view->headTitle()->append($overrides['title']);
        }

        $description = isset($overrides['description']) && $overrides['description'] !== ''
            ? $this->truncate($overrides['description'])
            : null;
        if ($description !== null) {
            $this->setDescription($view, $description);
        }

        $image = null;
        if (!empty($overrides['image'])) {
            $image = $this->assetUrl($view, (int) $overrides['image']);
        }
        if ($image !== null) {
            $this->setImage($view, $image);
        }

        $this->setOpenGraph($view, array_filter([
            'og:type'        => 'website',
            'og:title'       => $title,
            'og:url'         => $canonical,
            'og:description' => $description,
        ], static fn ($v) => $v !== null && $v !== ''));
        $this->markApplied('og:title');

        if (!empty($overrides['robots'])) {
            $this->setRobots($view, (string) $overrides['robots']);
        }

        if ($isHomepage && $this->jsonLdEnabled()) {
            $this->addJsonLd($view, $this->structuredData->webSite($view, $site));
        }

        // Bilingual: link this static page to its translated counterpart on the
        // other-language site (resolved from the configured page-slug map).
        $this->emitAlternates($view, $this->hreflang->forPage($view, $page, $site));
    }

    // ─── Phase 1: browse / search listing pages ─────────────────────────────

    public function applyBrowse(PhpRenderer $view, SiteRepresentation $site): void
    {
        // Self-referential canonical (full current URL) keeps facet/sort
        // variants from looking like duplicate content while staying safe for
        // paginated pages (no collapsing page 2 onto page 1).
        $current = $view->serverUrl(true);
        $this->setCanonical($view, $current);

        // Only noindex faceted / paginated / sorted variants (which carry a
        // query string). Clean landing pages stay indexable — crucially the
        // item-set pages (/item-set/{id}), which are listed in the sitemap;
        // marking them noindex made Search Console reject that sitemap.
        $hasQuery = ((string) (parse_url($current, PHP_URL_QUERY) ?? '')) !== '';
        if ($hasQuery && $this->boolSetting('iwac_seo_noindex_browse')) {
            $this->setRobots($view, 'noindex, follow');
        }
    }

    // ─── Phase 2: site-wide constants + gap-fill (view.layout) ──────────────

    public function applyGlobals(PhpRenderer $view, ?SiteRepresentation $site): void
    {
        $headMeta = $view->headMeta();

        // Master noindex (staging) overrides everything.
        if ($this->boolSetting('iwac_seo_noindex_site')) {
            $this->setRobots($view, 'noindex, nofollow', true);
        }

        // Verification tags — site-wide, on every public page.
        $gsc = $this->extractToken($this->stringSetting('iwac_seo_gsc_verification'), 'google-site-verification');
        if ($gsc !== '') {
            $headMeta->appendName('google-site-verification', $gsc);
        }
        $bing = $this->extractToken($this->stringSetting('iwac_seo_bing_verification'), 'msvalidate.01');
        if ($bing !== '') {
            $headMeta->appendName('msvalidate.01', $bing);
        }

        // Open Graph / Twitter constants.
        if ($site !== null) {
            $headMeta->setProperty('og:site_name', $site->title());
            $headMeta->setProperty('og:locale', $this->locale($view));
            // Advertise the other-language site(s) as og:locale:alternate.
            if ($this->hreflang->isEnabled()) {
                $currentSlug = $site->slug();
                foreach ($this->hreflang->sites() as $slug => $lang) {
                    if ($slug !== $currentSlug) {
                        $headMeta->appendProperty('og:locale:alternate', $this->ogLocale((string) $lang));
                    }
                }
            }
        }
        $headMeta->appendName('twitter:card', 'summary_large_image');
        $twitter = trim($this->stringSetting('iwac_seo_twitter_site'));
        if ($twitter !== '') {
            $headMeta->appendName('twitter:site', $twitter);
        }

        // Gap-fills (only when phase 1 set nothing).
        if (!$this->isApplied('description')) {
            $default = trim($this->stringSetting('iwac_seo_default_description'));
            if ($default !== '') {
                $this->setDescription($view, $this->truncate($default));
            }
        }
        if (!$this->isApplied('image')) {
            $img = $this->resolveDefaultImage($view);
            if ($img !== null) {
                $this->setImage($view, $img);
            }
        }
        if (!$this->isApplied('canonical')) {
            $this->setCanonical($view, $view->serverUrl(true));
        }
        if (!$this->isApplied('og:title')) {
            // Mirror the rendered <title>'s leading segment as og/twitter title.
            $titleParts = $view->headTitle()->getContainer()->getArrayCopy();
            $title = $titleParts[0] ?? ($site ? $site->title() : '');
            if ($title !== '') {
                $this->setOpenGraph($view, ['og:title' => (string) $title, 'og:type' => 'website']);
            }
        }
        // Mirror description → og/twitter description if still missing.
        if ($this->description !== null && !$this->isApplied('og:description')) {
            $this->setOpenGraph($view, ['og:description' => $this->description]);
        }
        if (!$this->isApplied('og:url')) {
            $headMeta->setProperty('og:url', $view->serverUrl(true));
        }
    }

    // ─── Low-level setters (track what was applied) ─────────────────────────

    public function setDescription(PhpRenderer $view, string $description): void
    {
        $view->headMeta()->setName('description', $description);
        // Twitter reads og:description, but set the explicit one too for clarity.
        $view->headMeta()->appendName('twitter:description', $description);
        $this->description = $description;
        $this->markApplied('description');
    }

    public function setCanonical(PhpRenderer $view, ?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }
        $view->headLink(['rel' => 'canonical', 'href' => $url]);
        $this->markApplied('canonical');
    }

    public function setImage(PhpRenderer $view, string $url): void
    {
        $this->setOpenGraph($view, ['og:image' => $url]);
        $view->headMeta()->appendName('twitter:image', $url);
        $this->markApplied('image');
    }

    public function setRobots(PhpRenderer $view, string $value, bool $force = false): void
    {
        if (!$force && $this->isApplied('robots')) {
            return;
        }
        $view->headMeta()->setName('robots', $value);
        $this->markApplied('robots');
    }

    /** @param array<string,string> $tags og property => content */
    public function setOpenGraph(PhpRenderer $view, array $tags): void
    {
        $headMeta = $view->headMeta();
        foreach ($tags as $property => $content) {
            if ($content === '' || $content === null) {
                continue;
            }
            $headMeta->setProperty($property, $content);
            // Twitter falls back to og:* for most fields; mirror title only.
            if ($property === 'og:title') {
                $headMeta->appendName('twitter:title', $content);
            }
            $this->markApplied($property);
        }
    }

    /** @param array<mixed> $data A JSON-LD document. */
    public function addJsonLd(PhpRenderer $view, array $data): void
    {
        // application/ld+json must not be wrapped in the JS CDATA/comment guard
        // HeadScript adds for inline scripts; disable it (HTML5 needs no guard).
        $view->headScript()->setAutoEscape(false);
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT
        );
        if ($json !== false) {
            $view->headScript()->appendScript($json, 'application/ld+json');
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
        // dcterms:description. Try them in that order.
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
            $title = $resource->resourceClass() ? $resource->resourceClass()->label() : 'Record';
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

    private function absoluteResourceUrl(
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): ?string {
        try {
            return $resource->siteUrl($site->slug(), true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveDefaultImage(PhpRenderer $view): ?string
    {
        if ($this->defaultImageResolved) {
            return $this->defaultImageUrl;
        }
        $this->defaultImageResolved = true;
        $assetId = (int) $this->stringSetting('iwac_seo_default_share_image');
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

    /**
     * Accept either a full <meta …> snippet pasted from the search console or a
     * bare token, and return just the token.
     */
    private function extractToken(string $raw, string $metaName): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (stripos($raw, '<meta') !== false
            && preg_match('/content\s*=\s*"([^"]+)"/i', $raw, $m)
        ) {
            return trim($m[1]);
        }
        // Strip accidental surrounding quotes/markup.
        return trim(strip_tags($raw), " \t\n\r\0\x0B\"'");
    }

    private function truncate(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::DESCRIPTION_MAX - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " ,.;:") . '…';
    }

    private function locale(PhpRenderer $view): string
    {
        // `lang` is a view helper invoked via __call, so method_exists($view,
        // 'lang') is always false — this used to pin og:locale to en_US even on
        // the French site. Resolve it through the plugin manager.
        $lang = 'en';
        try {
            $helpers = $view->getHelperPluginManager();
            if ($helpers->has('lang')) {
                $resolved = (string) $view->lang();
                if ($resolved !== '') {
                    $lang = $resolved;
                }
            }
        } catch (\Throwable $e) {
        }
        // BCP-47 (en-US) → Open Graph locale (en_US).
        return str_replace('-', '_', $lang) ?: 'en_US';
    }

    /**
     * Emit reciprocal hreflang alternate <link>s (plus x-default). A self link
     * is included so each language version references the whole set, as Google
     * requires. Skipped when there is fewer than two versions, keeping
     * single-language pages clean.
     *
     * @param array<int,array{lang:string,href:string,slug:string}> $alternates
     */
    private function emitAlternates(PhpRenderer $view, array $alternates): void
    {
        if (count($alternates) < 2) {
            return;
        }
        $xDefaultSlug = $this->hreflang->xDefaultSlug();
        $xDefaultHref = null;
        foreach ($alternates as $alt) {
            $view->headLink(['rel' => 'alternate', 'hreflang' => $alt['lang'], 'href' => $alt['href']]);
            if ($xDefaultSlug !== null && $alt['slug'] === $xDefaultSlug) {
                $xDefaultHref = $alt['href'];
            }
        }
        if ($xDefaultHref !== null) {
            $view->headLink(['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $xDefaultHref]);
        }
    }

    /** Map a bare hreflang code to an Open Graph locale (language_TERRITORY). */
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
        return $this->boolSetting('iwac_seo_jsonld_enabled', true);
    }

    private function markApplied(string $key): void
    {
        $this->applied[$key] = true;
    }

    private function isApplied(string $key): bool
    {
        return !empty($this->applied[$key]);
    }
}
