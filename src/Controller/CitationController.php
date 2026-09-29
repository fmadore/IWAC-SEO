<?php
declare(strict_types=1);

namespace IwacSeo\Controller;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Controller\Concern\SendsResponses;
use IwacSeo\Service\ResourceUrl;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Service\ViewLocale;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\SiteSettings;

/**
 * /cite/:id/:format — single-item citation downloads (BibTeX, RIS, CSL-JSON).
 *
 * Reuses the {@see CitationData} mapping + {@see CitationExport} serialisers that
 * back the item page's "How to cite" panel, so a scholar can save a record
 * straight into Zotero / Mendeley / EndNote / a LaTeX bibliography without the
 * BulkExport block. Zotero RDF stays on the sibling /unapi endpoint (it drives
 * the Connector). Only public, citable items resolve — authority records and
 * non-public items 404.
 *
 * Bilingual: `?site={slug}` names the site the reader is on, so the record
 * links that site's page and reads in its language (abstract, collection
 * name). Without it, or with a slug that is not a public site, the default
 * site answers — the route itself is unchanged.
 */
class CitationController extends AbstractActionController
{
    use SendsResponses;

    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationExport $citationExport,
        private readonly ApiManager $api,
        private readonly SettingsGate $settings,
        private readonly SiteResolver $siteResolver,
        private readonly SiteSettings $siteSettings,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->enabled()) {
            return $this->status(404);
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $format = (string) $this->params()->fromRoute('format', '');
        if ($id <= 0 || !isset(CitationExport::FORMATS[$format])) {
            return $this->status(404);
        }

        $item = $this->resolveItem($id);
        if ($item === null) {
            return $this->status(404);
        }

        $site = $this->readerSite();
        $record = $this->citationData->build(
            $item,
            ResourceUrl::forSite($item, $site?->slug()),
            $site !== null ? $this->siteLocale($site) : null
        );
        if ($record === null) {
            return $this->status(404); // authority record — not a citable work
        }

        $body = $this->citationExport->serialize($record, $format);
        if ($body === null) {
            return $this->status(404);
        }

        [, $contentType] = CitationExport::FORMATS[$format];
        return $this->fileResponse($body, $contentType, $this->citationExport->filename($record, $format));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolveItem(int $id): ?ItemRepresentation
    {
        try {
            $item = $this->api->read('items', $id)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException | \Omeka\Api\Exception\PermissionDeniedException $e) {
            return null;
        }
        if (!$item instanceof ItemRepresentation) {
            return null;
        }
        if (!$item->isPublic()) {
            return null;
        }
        return $this->citationData->isCitable(ResourceUrl::classId($item)) ? $item : null;
    }

    /** The public site named by ?site=, else the default site. */
    private function readerSite(): ?SiteRepresentation
    {
        $slug = $this->params()->fromQuery('site');
        $site = is_string($slug) && $slug !== '' ? $this->siteResolver->publicSite($slug) : null;
        return $site ?? $this->siteResolver->defaultSite();
    }

    /**
     * The site's own locale setting — what the page's translator uses, so a
     * download reads in the language of the page it came from.
     */
    private function siteLocale(SiteRepresentation $site): string
    {
        $locale = $this->siteSettings->get('locale', null, $site->id());
        if (!is_string($locale) || $locale === '') {
            $locale = $this->settings->text('locale');
        }
        return ViewLocale::narrow($locale);
    }

    private function enabled(): bool
    {
        // Shares the citation kill-switch with the Highwire/DC meta tags.
        return $this->settings->isOn('iwac_seo_citation_meta', true);
    }

    private function fileResponse(string $content, string $contentType, string $filename): Response
    {
        // filename() is sanitised to [A-Za-z0-9._-], safe inside the header.
        return $this->respond($content, $contentType, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function status(int $code): Response
    {
        return $this->respondWithStatus($code);
    }
}
