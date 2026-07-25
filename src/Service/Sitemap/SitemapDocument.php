<?php
declare(strict_types=1);

namespace IwacSeo\Service\Sitemap;

/**
 * A rendered sitemap document and the moment it was generated.
 *
 * The generation time used to be read back off the *shared* generator through
 * a lastModified() accessor after the build call — mutable state on a shared
 * service, plus a temporal coupling the controller had to honour. Returning it
 * alongside the XML removes both: a document knows its own age.
 */
final class SitemapDocument
{
    public function __construct(
        public readonly string $xml,
        /** Unix timestamp: the cache file's mtime on a hit, the build moment otherwise. */
        public readonly int $lastModified,
    ) {
    }

    /** The value for an HTTP Last-Modified header. */
    public function lastModifiedHeader(): string
    {
        return gmdate('D, d M Y H:i:s', $this->lastModified) . ' GMT';
    }
}
