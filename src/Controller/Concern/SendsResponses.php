<?php
declare(strict_types=1);

namespace IwacSeo\Controller\Concern;

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
 * the raw feed never competes with the HTML page it describes.
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

    /** A bare status response with no body (404, 406 …). */
    private function respondWithStatus(int $code): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        return $response;
    }
}
