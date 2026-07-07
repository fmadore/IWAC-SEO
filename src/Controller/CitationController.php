<?php
declare(strict_types=1);

namespace IwacSeo\Controller;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;

/**
 * /cite/:id/:format — single-item citation downloads (BibTeX, RIS, CSL-JSON).
 *
 * Reuses the {@see CitationData} mapping + {@see CitationExport} serialisers that
 * back the item page's "How to cite" panel, so a scholar can save a record
 * straight into Zotero / Mendeley / EndNote / a LaTeX bibliography without the
 * BulkExport block. Zotero RDF stays on the sibling /unapi endpoint (it drives
 * the Connector). Only public, citable items resolve — authority records and
 * non-public items 404.
 */
class CitationController extends AbstractActionController
{
    public function __construct(
        private readonly CitationData $citationData,
        private readonly CitationExport $citationExport,
        private readonly ApiManager $api,
        private readonly Settings $settings,
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

        $record = $this->citationData->build($item, $this->itemUrl($item));
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
        } catch (\Throwable $e) {
            return null;
        }
        if (!$item instanceof ItemRepresentation) {
            return null;
        }
        if (method_exists($item, 'isPublic') && !$item->isPublic()) {
            return null;
        }
        $classId = $item->resourceClass() ? $item->resourceClass()->id() : null;
        return $this->citationData->isCitable($classId) ? $item : null;
    }

    /** The default site's public page URL — the citation's stable canonical. */
    private function itemUrl(ItemRepresentation $item): ?string
    {
        $slug = $this->defaultSiteSlug();
        if ($slug === null) {
            return null;
        }
        try {
            return $item->siteUrl($slug, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function defaultSiteSlug(): ?string
    {
        try {
            $defaultSiteId = (int) $this->settings->get('default_site');
            if ($defaultSiteId) {
                return $this->api->read('sites', $defaultSiteId)->getContent()->slug();
            }
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
            return $sites ? $sites[0]->slug() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function enabled(): bool
    {
        // Shares the citation kill-switch with the Highwire/DC meta tags.
        $value = $this->settings->get('iwac_seo_citation_meta', '1');
        return $value === '1' || $value === 1 || $value === true;
    }

    private function fileResponse(string $content, string $contentType, string $filename): Response
    {
        $response = $this->getResponse();
        $response->setContent($content);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $contentType);
        // filename() is sanitised to [A-Za-z0-9._-], safe inside the header.
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $headers->addHeaderLine('X-Robots-Tag', 'noindex');
        return $response;
    }

    private function status(int $code): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        return $response;
    }
}
