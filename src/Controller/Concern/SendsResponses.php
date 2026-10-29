<?php
declare(strict_types=1);

namespace IwacSeo\Controller\Concern;

use IwacSeo\Service\HttpCache;
use Laminas\Http\Response;

/**
 * Body-and-headers plumbing for the module's non-HTML endpoints (sitemap,
 * robots, unAPI, citation downloads).
 *
 * These controllers return raw XML/text/JSON rather than a ViewModel, so each
 * had hand-rolled its own `status()`, `notFound()`, `xml()`, `text()`,
 * `body()` and `fileResponse()` — six near-identical private helpers across
 * three classes. The one behavioural rule they share is worth stating once:
 * every machine-readable representation is served `X-Robots-Tag: noindex`, so
 * the raw feed never competes with the HTML page it describes. The citation
 * documents (the /cite downloads, unAPI's Zotero RDF and format list) are
 * also cacheable, through {@see respondCacheable()}.
 *
 * Requires the using class to be a Laminas AbstractActionController (for
 * getResponse()).
 */
trait SendsResponses
{
    /**
     * @param array<string,string> $headers extra header lines
     */
    private function respond(
        string $body,
        string $contentType,
        int $status = 200,
        array $headers = [],
        bool $noindex = true
    ): Response {
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->setContent($body);

        $responseHeaders = $response->getHeaders();
        $responseHeaders->addHeaderLine('Content-Type', $contentType);
        if ($noindex) {
            $responseHeaders->addHeaderLine('X-Robots-Tag', 'noindex');
        }
        foreach ($headers as $name => $value) {
            $responseHeaders->addHeaderLine($name, $value);
        }
        return $response;
    }

    /**
     * A generated document with cache validators ({@see HttpCache}): a strong
     * ETag over the body, the record's last change as Last-Modified, and a
     * short public max-age, so a reference manager or a cache revalidates
     * cheaply. A conditional GET whose copy is current is answered 304, with
     * the validators and no body.
     *
     * @param array<string,string> $headers extra header lines, sent with the body only
     */
    private function respondCacheable(
        string $body,
        string $contentType,
        ?\DateTimeInterface $modified,
        int $status = 200,
        array $headers = []
    ): Response {
        $etag = HttpCache::etag($body);
        $validators = ['Cache-Control' => 'public, max-age=' . HttpCache::MAX_AGE, 'ETag' => $etag];
        $lastModified = HttpCache::lastModified($modified);
        if ($lastModified !== null) {
            $validators['Last-Modified'] = $lastModified;
        }

        $request = $this->getRequest();
        if (
            ($request->isGet() || $request->isHead())
            && HttpCache::notModified(
                $etag,
                $modified,
                self::requestHeader($request, 'If-None-Match'),
                self::requestHeader($request, 'If-Modified-Since')
            )
        ) {
            $response = $this->respondWithStatus(304);
            $response->setContent('');
            $responseHeaders = $response->getHeaders();
            foreach ($validators + ['X-Robots-Tag' => 'noindex'] as $name => $value) {
                $responseHeaders->addHeaderLine($name, $value);
            }
            return $response;
        }
        return $this->respond($body, $contentType, $status, $validators + $headers);
    }

    /** A request header's value, or null when it is absent or unreadable. */
    private static function requestHeader(object $request, string $name): ?string
    {
        try {
            $header = $request->getHeader($name);
        } catch (\Throwable $e) {
            return null; // a malformed date, say: no condition to meet
        }
        return is_object($header) ? (string) $header->getFieldValue() : null;
    }

    /** A bare status response with no body (404, 406 …). */
    private function respondWithStatus(int $code): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        return $response;
    }
}
