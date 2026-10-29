<?php
declare(strict_types=1);

namespace IwacSeo\Controller;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Controller\Concern\SendsResponses;
use IwacSeo\Service\PageCitationData;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\ResourceUrl;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Service\ViewLocale;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
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
 *
 * /cite/page/:id/:format — the same downloads for a static site page (an
 * online exhibit), built by {@see PageCitationData}. A page belongs to one
 * site, so no ?site= is read: the page's own site fixes its URL and language.
 * Only a public page of a public site that carries the "How to cite this page"
 * block resolves.
 *
 * Both are served with an ETag over the file and the record's (or page's)
 * last change as Last-Modified, and may be cached for five minutes, so a
 * reference manager re-fetching a citation revalidates cheaply
 * ({@see \IwacSeo\Service\HttpCache}).
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
        private readonly PageCitationData $pageCitationData,
        private readonly PageSeoStore $pageSeoStore,
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
        return $this->fileResponse(
            $body,
            $contentType,
            $this->citationExport->filename($record, $format),
            self::modified(static fn () => $item->modified() ?? $item->created())
        );
    }

    public function pageAction(): Response
    {
        if (!$this->enabled()) {
            return $this->status(404);
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $format = (string) $this->params()->fromRoute('format', '');
        if ($id <= 0 || !isset(CitationExport::FORMATS[$format])) {
            return $this->status(404);
        }

        $page = $this->resolvePage($id);
        if ($page === null) {
            return $this->status(404);
        }

        $site = $page->site();
        $this->pageSeoStore->setSite($site->id());
        $record = $this->pageCitationData->build(
            $page,
            ResourceUrl::forSite($page, $site->slug()),
            $this->siteLocale($site),
            $this->pageSeoStore->get($page->id())
        );

        $body = $this->citationExport->serialize($record, $format);
        if ($body === null) {
            return $this->status(404);
        }

        [, $contentType] = CitationExport::FORMATS[$format];
        // A page citation dates from the page's own change, not its site's.
        return $this->fileResponse(
            $body,
            $contentType,
            $this->citationExport->filename($record, $format),
            self::modified(static fn () => $page->modified() ?? $page->created())
        );
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** A public, citable page of a public site, else null. */
    private function resolvePage(int $id): ?SitePageRepresentation
    {
        try {
            $page = $this->api->read('site_pages', $id)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException | \Omeka\Api\Exception\PermissionDeniedException $e) {
            return null;
        }
        if (!$page instanceof SitePageRepresentation || !$page->isPublic() || !$page->site()->isPublic()) {
            return null;
        }
        return PageCitationData::isCitable($page) ? $page : null;
    }

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

    private function fileResponse(string $content, string $contentType, string $filename, ?\DateTimeInterface $modified): Response
    {
        // filename() is sanitised to [A-Za-z0-9._-], safe inside the header.
        return $this->respondCacheable($content, $contentType, $modified, 200, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * When the record last changed, else when it was made, for Last-Modified;
     * null when the representation cannot say.
     *
     * @param callable():mixed $read
     */
    private static function modified(callable $read): ?\DateTimeInterface
    {
        try {
            $date = $read();
        } catch (\Throwable $e) {
            return null;
        }
        return $date instanceof \DateTimeInterface ? $date : null;
    }

    private function status(int $code): Response
    {
        return $this->respondWithStatus($code);
    }
}
