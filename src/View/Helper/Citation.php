<?php
declare(strict_types=1);

namespace IwacSeo\View\Helper;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\ZoteroRdf;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * iwacCitation($item) — the view-model the theme renders as the item page's
 * "How to cite" panel. The mapping + formatting live here (IWAC-SEO owns the
 * citation contract, avoiding duplication with the Zotero/Scholar meta tags);
 * the theme owns the markup and styling.
 *
 * Returns null when the panel should not appear — the feature is off, or the
 * resource is an authority record (person / place / organisation / …), which is
 * not a citable work. Otherwise returns:
 *   [
 *     'record'       => <normalized CitationData record>,
 *     'defaultStyle' => 'chicago',
 *     'styles'       => ['chicago' => ['label'=>…, 'html'=>…], 'apa'=>…, 'mla'=>…],
 *     'downloads'    => ['bibtex' => ['url'=>…, 'label'=>…, 'ext'=>…], 'ris'=>…, …],
 *     'zoteroRdf'    => <unAPI RDF url> | null,   // only for Connector-eligible kinds
 *   ]
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
        private readonly CitationExport $export,
        private readonly ZoteroRdf $zoteroRdf,
        private readonly string $defaultStyle,
        private readonly array $styleLabels,
        private readonly array $enabledFormats,
        private readonly bool $enabled,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function __invoke(ItemRepresentation $item): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $classId = $item->resourceClass() ? $item->resourceClass()->id() : null;
        if (!$this->citationData->isCitable($classId)) {
            return null;
        }

        $view = $this->getView();
        $url = $this->itemUrl($view, $item);

        $record = $this->citationData->build($item, $url);
        if ($record === null) {
            return null;
        }

        $locale = $this->locale($view);

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
                'url'   => $view->serverUrl('/cite/' . $record['id'] . '/' . $fmt),
                'label' => self::FORMAT_LABELS[$fmt] ?? strtoupper($fmt),
                'ext'   => CitationExport::FORMATS[$fmt][0],
            ];
        }

        $zoteroRdf = null;
        if ($url !== null && $this->zoteroRdf->isEligible($classId)) {
            $zoteroRdf = $view->serverUrl('/unapi') . '?id=' . rawurlencode($url) . '&format=rdf_zotero';
        }

        return [
            'record'       => $record,
            'defaultStyle' => isset($styles[$this->defaultStyle]) ? $this->defaultStyle : (string) array_key_first($styles),
            'styles'       => $styles,
            'downloads'    => $downloads,
            'zoteroRdf'    => $zoteroRdf,
        ];
    }

    private function itemUrl($view, ItemRepresentation $item): ?string
    {
        try {
            $site = $view->currentSite();
            if ($site) {
                return $item->siteUrl($site->slug(), true);
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    private function locale($view): string
    {
        // `lang` and `siteSetting` are view helpers invoked via __call, so they
        // must be resolved through the plugin manager — method_exists($view,
        // 'lang') is ALWAYS false and silently forced English dates on the French
        // site (the citation text stayed "May 13, 2025" while the chrome was
        // French). Prefer the active translator locale (matches the translated
        // chrome), fall back to the site's configured locale.
        $lang = '';
        try {
            $helpers = $view->getHelperPluginManager();
            if ($helpers->has('lang')) {
                $lang = (string) $view->lang();
            }
            if ($lang === '' && $helpers->has('siteSetting')) {
                $lang = (string) ($view->siteSetting('locale') ?? '');
            }
        } catch (\Throwable $e) {
        }
        return str_starts_with(strtolower($lang), 'fr') ? 'fr' : 'en';
    }
}
