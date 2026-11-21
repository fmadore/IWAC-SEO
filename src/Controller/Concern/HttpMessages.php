<?php
declare(strict_types=1);

namespace IwacSeo\Controller\Concern;

use Laminas\Http\Request;
use Laminas\Http\Response;

/**
 * The controller's request and response as the HTTP messages they are.
 *
 * Laminas types getRequest() and getResponse() as the protocol-neutral
 * Stdlib interfaces, which carry no headers, methods or status codes. Under
 * Omeka's HTTP application they are always Laminas\Http messages; checking
 * that once here keeps every caller typed instead of trusting it silently.
 *
 * Requires the using class to be a Laminas AbstractController.
 */
trait HttpMessages
{
    private function httpRequest(): Request
    {
        $request = $this->getRequest();
        if (!$request instanceof Request) {
            throw new \LogicException('IwacSeo controllers serve HTTP requests only.');
        }
        return $request;
    }

    private function httpResponse(): Response
    {
        $response = $this->getResponse();
        if (!$response instanceof Response) {
            throw new \LogicException('IwacSeo controllers answer with HTTP responses only.');
        }
        return $response;
    }
}
