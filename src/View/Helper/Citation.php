<?php
declare(strict_types=1);

namespace IwacSeo\View\Helper;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\PageCitationData;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\ResourceUrl;
use IwacSeo\Service\ViewLocale;
use IwacSeo\Service\ZoteroRdf;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;

/**
 * iwacCitation($item) — the view-model the theme renders as the item page's
 * "How to cite" panel; iwacCitation($page) — the same for a static site page
 * (an online exhibit) carrying the "How to cite this page" block. The mapping + formatting live here (IWAC-SEO owns the
 * citation contract, avoiding duplication with the Zotero/Scholar meta tags);
 * the theme owns the markup and styling.
 *
 * Returns null when the panel should not appear — the feature is off, the
 * resource is an authority record (person / place / organisation / …), which is
 * not a citable work, or the page does not carry the block. Otherwise returns:
 *   [
 *     'record'       => <CitationRecord::toArray()>,
 *     'defaultStyle' => 'chicago',
 *     'styles'       => ['chicago' => ['label'=>…, 'html'=>…], 'apa'=>…, 'mla'=>…],
 *     'downloads'    => ['bibtex' => ['url'=>…, 'label'=>…, 'ext'=>…], 'ris'=>…, …],
 *     'zoteroRdf'    => <unAPI RDF url> | null,   // only for Connector-eligible kinds
 *   ]
 * A page's record has kind 'webpage', which is how the theme words its heading.
 */
class Citation extends AbstractHelper
{
    private const FORMAT_LABELS = [
        'bibtex'  => 'BibTeX',
        'ris'     => 'RIS',
        'csljson' => 'CSL JSON',
    ];

    /**
     * @param array<string,string> $styleLabels  style id => display label (ordered)
     * @param string[]             $enabledFormats
     */
    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationFormatter $formatter,
        private readonly ZoteroRdf $zoteroRdf,
        private readonly string $defaultStyle,
        private readonly array $styleLabels,
        private readonly array $enabledFormats,
        private readonly bool $enabled,
        private readonly ?PageCitationData $pageCitationData = null,
        private readonly ?PageSeoStore $pageSeoStore = null,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function __invoke(ItemRepresentation|SitePageRepresentation $resource): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        return $resource instanceof SitePageRepresentation
            ? $this->forPage($resource)
            : $this->forItem($resource);
    }

    /** @return array<string,mixed>|null */
    private function forItem(ItemRepresentation $item): ?array
    {
        $classId = ResourceUrl::classId($item);
        if (!$this->citationData->isCitable($classId)) {
            return null;
        }

        /** @var PhpRenderer $view */
        $view = $this->getView();
        $siteSlug = $this->siteSlug($view);
        $url = ResourceUrl::forSite($item, $siteSlug);
        $locale = ViewLocale::forCitation($view);

        $record = $this->citationData->build($item, $url, $locale);
        if ($record === null) {
            return null;
        }

        $zoteroRdf = null;
        if ($url !== null && $this->zoteroRdf->isEligible($classId)) {
            $zoteroRdf = $view->serverUrl('/unapi') . '?id=' . rawurlencode($url) . '&format=rdf_zotero';
        }

        // Downloads carry the reader's site, so the file links this page and
        // reads in its language rather than the default site's.
        $siteQuery = $siteSlug !== null ? '?site=' . rawurlencode($siteSlug) : '';
        return $this->viewModel($record, $locale, '/cite/' . $record->id . '/', $siteQuery, $zoteroRdf);
    }

    /**
     * A static page cites itself: its own site's URL, in the language of the
     * page being read, with the editor's citation fields from the static-page
     * SEO table. Only a page carrying the "How to cite this page" block — the
     * rule the download route applies too.
     *
     * @return array<string,mixed>|null
     */
    private function forPage(SitePageRepresentation $page): ?array
    {
        if ($this->pageCitationData === null || !PageCitationData::isCitable($page)) {
            return null;
        }
        /** @var PhpRenderer $view */
        $view = $this->getView();
        $site = $page->site();
        $overrides = [];
        if ($this->pageSeoStore !== null) {
            $this->pageSeoStore->setSite($site->id());
            $overrides = $this->pageSeoStore->get($page->id());
        }
        $locale = ViewLocale::forCitation($view);
        $record = $this->pageCitationData->build($page, ResourceUrl::forSite($page, $site->slug()), $locale, $overrides);

        // A page has no unAPI identity: the Zotero Connector reads the page
        // itself, and the downloads carry the authored record.
        return $this->viewModel($record, $locale, '/cite/page/' . $page->id() . '/', '', null);
    }

    /**
     * The view-model items and pages share: each style formatted, and a
     * download link per enabled format.
     *
     * @return array<string,mixed>|null
     */
    private function viewModel(CitationRecord $record, string $locale, string $downloadPath, string $query, ?string $zoteroRdf): ?array
    {
        /** @var PhpRenderer $view */
        $view = $this->getView();

        $styles = [];
        foreach ($this->styleLabels as $id => $label) {
            $styles[$id] = [
                'label' => $label,
                'html'  => $this->formatter->format($record, (string) $id, $locale),
            ];
        }
        if (!$styles) {
            return null;
        }

        $downloads = [];
        foreach ($this->enabledFormats as $fmt) {
            if (!isset(CitationExport::FORMATS[$fmt])) {
                continue;
            }
            $downloads[$fmt] = [
                'url'   => $view->serverUrl($downloadPath . $fmt) . $query,
                'label' => self::FORMAT_LABELS[$fmt] ?? strtoupper($fmt),
                'ext'   => CitationExport::FORMATS[$fmt][0],
            ];
        }

        return [
            // The theme reads the record as an array; toArray() is that
            // published contract, and the only place the array form is built.
            'record'       => $record->toArray(),
            'defaultStyle' => isset($styles[$this->defaultStyle]) ? $this->defaultStyle : (string) array_key_first($styles),
            'styles'       => $styles,
            'downloads'    => $downloads,
            'zoteroRdf'    => $zoteroRdf,
        ];
    }

    private function siteSlug(PhpRenderer $view): ?string
    {
        try {
            $site = $view->currentSite();
        } catch (\Throwable $e) {
            return null;
        }
        return $site ? $site->slug() : null;
    }
}
