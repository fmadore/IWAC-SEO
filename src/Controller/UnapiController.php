<?php
declare(strict_types=1);

namespace IwacSeo\Controller;

use IwacSeo\Service\Concern\SettingsReader;
use IwacSeo\Service\ZoteroRdf;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;

/**
 * unAPI endpoint (/unapi) — serves IWAC primary-source items as Zotero RDF.
 *
 *   GET /unapi                                  → supported-formats list (HTTP 200)
 *   GET /unapi?id={itemURL}                     → formats for that id (HTTP 300)
 *   GET /unapi?id={itemURL}&format=rdf_zotero   → the item as Zotero RDF (HTTP 200)
 *
 * The id is the item's public page URL — the value of the page's
 * <abbr class="unapi-id"> (emitted by HeadMetadata). Zotero ranks the unAPI
 * translator (priority 300) above Embedded Metadata (400), so when both are
 * present the Connector imports this RDF instead of scraping the meta tags,
 * which is what lets us set the call number (Cote) and single-field creators.
 *
 * @see \IwacSeo\Service\ZoteroRdf
 */
class UnapiController extends AbstractActionController
{
    use SettingsReader;

    /** The only format we serve; rdf_zotero is Zotero's most-preferred unAPI format. */
    private const FORMAT = 'rdf_zotero';

    public function __construct(
        private readonly ZoteroRdf $zoteroRdf,
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function indexAction(): Response
    {
        if (!$this->enabled()) {
            return $this->status(404);
        }

        $id = trim((string) $this->params()->fromQuery('id', ''));
        $format = trim((string) $this->params()->fromQuery('format', ''));

        // No id → advertise the server's formats (Zotero's first, default request).
        if ($id === '') {
            return $this->formats(200);
        }

        $item = $this->resolveItem($id);
        if ($item === null) {
            return $this->status(404);
        }

        // id but no format → the formats available for this id (unAPI: HTTP 300).
        if ($format === '') {
            return $this->formats(300);
        }
        if ($format !== self::FORMAT) {
            return $this->status(406);
        }

        $rdf = $this->zoteroRdf->render($item, $id);
        if ($rdf === null) {
            return $this->status(404);
        }
        return $this->body($rdf, 'application/rdf+xml; charset=utf-8');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Resolve a unAPI id (an item page URL on this host) to a public, eligible
     * item. Rejects ids that are not for this host so the endpoint cannot be
     * used to reflect arbitrary URLs into the served RDF.
     */
    private function resolveItem(string $id): ?ItemRepresentation
    {
        $host = $this->getRequest()->getUri()->getHost();
        $idHost = parse_url($id, PHP_URL_HOST);
        if ($host === null || $idHost === null || strcasecmp($host, (string) $idHost) !== 0) {
            return null;
        }
        if (!preg_match('#/item/(\d+)#', $id, $m)) {
            return null;
        }

        try {
            $item = $this->api->read('items', (int) $m[1])->getContent();
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
        return $this->zoteroRdf->isEligible($classId) ? $item : null;
    }

    private function formats(int $status): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<formats>' . "\n"
            . '  <format name="' . self::FORMAT . '" type="application/rdf+xml"/>' . "\n"
            . '</formats>' . "\n";
        return $this->body($xml, 'application/xml; charset=utf-8', $status);
    }

    private function enabled(): bool
    {
        return $this->boolSetting('iwac_seo_unapi', true);
    }

    private function body(string $content, string $contentType, int $status = 200): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->setContent($content);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $contentType);
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
